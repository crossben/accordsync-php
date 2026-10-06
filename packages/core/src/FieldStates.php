<?php

declare(strict_types=1);

namespace Accord\Core;

/** Builds field states, empty or from a snapshot. */
final class FieldStates
{
    public static function empty(Strategy $strategy): FieldState
    {
        return match ($strategy) {
            Strategy::Lww => new LwwState(),
            Strategy::Counter => new CounterState(),
            Strategy::Set => new SetState(),
            Strategy::Conflict => new ConflictState(),
        };
    }

    /** The inverse of {@see FieldState::snapshot()}; checks the shape, since snapshots come over the network. */
    public static function fromSnapshot(mixed $snap): FieldState
    {
        if (!$snap instanceof JsonObject) {
            throw new AccordException('field snapshot must be an object');
        }
        switch ($snap->get('strategy')) {
            case 'lww':
                $s = new LwwState();
                $w = $snap->get('winner');
                if ($w !== null) {
                    if (!$w instanceof JsonObject || !\is_string($w->get('opId')) || !\is_string($w->get('hlc'))) {
                        throw new AccordException('malformed lww snapshot');
                    }
                    $s->winner = new AssignOp($w->get('opId'), '', '', Hlc::decode($w->get('hlc')), $w->get('value'), []);
                }

                return $s;
            case 'counter':
                $total = $snap->get('total');
                if (!\is_int($total)) {
                    throw new AccordException('malformed counter snapshot');
                }
                $s = new CounterState();
                $s->total = $total;

                return $s;
            case 'set':
                $s = new SetState();
                foreach (self::pairs($snap->get('tags')) as [$tag, $element]) {
                    if (!Elements::isElement($element)) {
                        throw new AccordException('malformed set snapshot');
                    }
                    $s->tags[$tag] = $element;
                }

                return $s;
            case 'conflict':
                $s = new ConflictState();
                foreach (self::pairs($snap->get('live')) as [$opId, $value]) {
                    $s->live[$opId] = $value;
                }

                return $s;
            default:
                throw new AccordException('unknown snapshot strategy ' . Json::canonical($snap->get('strategy')));
        }
    }

    /** @return list<array{string, mixed}> */
    private static function pairs(mixed $xs): array
    {
        if (!\is_array($xs) || !array_is_list($xs)) {
            throw new AccordException('snapshot entries must be an array');
        }
        $out = [];
        foreach ($xs as $p) {
            if (!\is_array($p) || \count($p) !== 2 || !\is_string($p[0] ?? null) || !\array_key_exists(1, $p)) {
                throw new AccordException('snapshot entries must be [opId, value] pairs');
            }
            $out[] = [$p[0], $p[1]];
        }

        return $out;
    }
}
