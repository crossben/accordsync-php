<?php

declare(strict_types=1);

namespace Accord\Core;

/** Every value no later assign has seen stays live; more than one live value is a conflict. */
final class ConflictState implements FieldState
{
    /** @var array<string, mixed> op id → value */
    public array $live = [];

    /** @var array<string, true> superseded op ids: an assign arriving after its successor stays dead */
    public array $superseded = [];

    public function strategy(): Strategy
    {
        return Strategy::Conflict;
    }

    public function apply(Op $op): void
    {
        if (!$op instanceof AssignOp) {
            throw new AccordException("op kind \"{$op->kind()}\" does not apply to a conflict field");
        }
        foreach ($op->deps as $dep) {
            $this->superseded[$dep] = true;
            unset($this->live[$dep]);
        }
        if (!isset($this->superseded[$op->opId])) {
            $this->live[$op->opId] = $op->value;
        }
    }

    /** `{value: v}`, or `{conflicted: [{value, opId}, …]}` sorted by op id. */
    public function read(): mixed
    {
        $ids = SetState::sorted($this->live);
        if ($ids === []) {
            return Absent::Value;
        }
        if (\count($ids) === 1) {
            return new JsonObject(['value' => $this->live[$ids[0]]]);
        }

        return new JsonObject([
            'conflicted' => array_map(fn(string $id) => new JsonObject(['value' => $this->live[$id], 'opId' => $id]), $ids),
        ]);
    }

    public function observedDeps(string|int|float|null $element = null): array
    {
        return SetState::sorted($this->live);
    }

    public function isConflicted(): bool
    {
        return \count($this->live) > 1;
    }

    public function snapshot(): JsonObject
    {
        $live = [];
        foreach (SetState::sorted($this->live) as $id) {
            $live[] = [$id, $this->live[$id]];
        }

        return new JsonObject(['strategy' => 'conflict', 'live' => $live]);
    }
}
