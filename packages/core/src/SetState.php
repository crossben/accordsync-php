<?php

declare(strict_types=1);

namespace Accord\Core;

/** Add-wins set: each add is a tag; a remove (or a re-add) retires the tags its writer saw. */
final class SetState implements FieldState
{
    /** @var array<string, string|int|float> tag (the op id of the add) → element */
    public array $tags = [];

    /** @var array<string, true> retired tags: an add arriving after its remove stays removed */
    public array $removed = [];

    public function strategy(): Strategy
    {
        return Strategy::Set;
    }

    public function apply(Op $op): void
    {
        if (!$op instanceof AddOp && !$op instanceof RemoveOp) {
            throw new AccordException("op kind \"{$op->kind()}\" does not apply to a set field");
        }
        foreach ($op->deps as $tag) {
            $this->removed[$tag] = true;
            unset($this->tags[$tag]);
        }
        if ($op instanceof AddOp && !isset($this->removed[$op->opId])) {
            $this->tags[$op->opId] = $op->element;
        }
    }

    public function read(): mixed
    {
        $unique = [];
        foreach ($this->tags as $e) {
            foreach ($unique as $u) {
                if (Elements::same($u, $e)) {
                    continue 2;
                }
            }
            $unique[] = $e;
        }
        usort($unique, Elements::compare(...));

        return $unique;
    }

    public function observedDeps(string|int|float|null $element = null): array
    {
        $out = [];
        foreach ($this->tags as $tag => $e) {
            if (Elements::same($e, $element)) {
                $out[] = (string) $tag;
            }
        }
        usort($out, Op::compareIds(...));

        return $out;
    }

    public function snapshot(): JsonObject
    {
        $tags = [];
        foreach (self::sorted($this->tags) as $tag) {
            $tags[] = [$tag, $this->tags[$tag]];
        }

        return new JsonObject(['strategy' => 'set', 'tags' => $tags]);
    }

    /**
     * @param array<string, mixed> $byOpId
     * @return list<string>
     */
    public static function sorted(array $byOpId): array
    {
        $ids = array_map(strval(...), array_keys($byOpId));
        usort($ids, Op::compareIds(...));

        return $ids;
    }
}
