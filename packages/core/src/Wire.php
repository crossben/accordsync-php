<?php

declare(strict_types=1);

namespace Accord\Core;

/** An op as it travels over the network and sits in golden vectors. Port of `wire.ts`. */
final class Wire
{
    public static function encode(Op $op): JsonObject
    {
        $o = new JsonObject(['op_id' => $op->opId, 'record' => $op->record, 'field' => $op->field, 'hlc' => $op->hlc->encode()]);
        $o->set('kind', $op->kind());
        if ($op instanceof AssignOp) {
            $o->set('value', $op->value);
            $o->set('deps', $op->deps);
        } elseif ($op instanceof IncOp) {
            $o->set('by', $op->by);
        } elseif ($op instanceof AddOp) {
            $o->set('element', $op->element);
            // `deps` is omitted when empty, so first adds keep the v0.1 wire shape.
            if ($op->deps !== []) {
                $o->set('deps', $op->deps);
            }
        } elseif ($op instanceof RemoveOp) {
            $o->set('element', $op->element);
            $o->set('deps', $op->deps);
        }

        return $o;
    }

    /** Parses untrusted input (a decoded JSON value) into an op, or throws with the reason. Schema checks happen later. */
    public static function decode(mixed $input): Op
    {
        if (!$input instanceof JsonObject) {
            throw new AccordException('op must be an object');
        }
        $o = $input;
        $opId = self::str($o, 'op_id');
        $record = self::str($o, 'record');
        $field = self::str($o, 'field');
        $hlc = Hlc::decode(self::str($o, 'hlc'));
        $device = Op::parseId($opId)['device'];
        Op::recordType($record);
        if ($device !== $hlc->node) {
            throw new AccordException("op $opId carries a clock from \"{$hlc->node}\"");
        }

        switch ($o->get('kind')) {
            case 'assign':
                if (!$o->has('value')) {
                    throw new AccordException('assign needs a value');
                }

                return new AssignOp($opId, $record, $field, $hlc, $o->get('value'), self::deps($o));
            case 'inc':
                return new IncOp($opId, $record, $field, $hlc, self::by($o->get('by')));
            case 'add':
                return new AddOp($opId, $record, $field, $hlc, self::element($o), $o->has('deps') ? self::deps($o) : []);
            case 'remove':
                return new RemoveOp($opId, $record, $field, $hlc, self::element($o), self::deps($o));
            default:
                throw new AccordException('unknown op kind ' . (\is_string($o->get('kind')) ? $o->get('kind') : get_debug_type($o->get('kind'))));
        }
    }

    /** Number.isSafeInteger: a whole-number float (`3.0`) is the same number in JavaScript. */
    private static function by(mixed $by): int
    {
        if (\is_float($by) && is_finite($by) && floor($by) === $by && abs($by) <= Json::MAX_SAFE_INTEGER) {
            return (int) $by;
        }
        if (!\is_int($by) || abs($by) > Json::MAX_SAFE_INTEGER) {
            throw new AccordException('inc needs an integer "by"');
        }

        return $by;
    }

    private static function str(JsonObject $o, string $key): string
    {
        $v = $o->get($key);
        if (!\is_string($v)) {
            throw new AccordException("\"$key\" must be a string");
        }

        return $v;
    }

    /** @return list<string> */
    private static function deps(JsonObject $o): array
    {
        $d = $o->get('deps');
        if (!\is_array($d) || !array_is_list($d)) {
            throw new AccordException('"deps" must be an array of op ids');
        }
        $ids = [];
        foreach ($d as $id) {
            if (!\is_string($id)) {
                throw new AccordException('"deps" must contain strings');
            }
            Op::parseId($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private static function element(JsonObject $o): string|int|float
    {
        $e = $o->get('element');
        if (\is_string($e) || \is_int($e) || (\is_float($e) && is_finite($e))) {
            return $e;
        }
        throw new AccordException('"element" must be a string or finite number');
    }
}
