<?php

declare(strict_types=1);

namespace Accord\Core;

/** Highest clock wins. */
final class LwwState implements FieldState
{
    public ?AssignOp $winner = null;

    public function strategy(): Strategy
    {
        return Strategy::Lww;
    }

    public function apply(Op $op): void
    {
        if (!$op instanceof AssignOp) {
            throw new AccordException("op kind \"{$op->kind()}\" does not apply to a lww field");
        }
        if ($this->winner === null || Hlc::compare($op->hlc, $this->winner->hlc) > 0) {
            $this->winner = $op;
        }
    }

    public function read(): mixed
    {
        return $this->winner === null ? Absent::Value : $this->winner->value;
    }

    public function observedDeps(string|int|float|null $element = null): array
    {
        return [];
    }

    public function snapshot(): JsonObject
    {
        $w = $this->winner;

        return new JsonObject([
            'strategy' => 'lww',
            'winner' => $w === null ? null : new JsonObject(['opId' => $w->opId, 'hlc' => $w->hlc->encode(), 'value' => $w->value]),
        ]);
    }
}
