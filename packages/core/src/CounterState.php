<?php

declare(strict_types=1);

namespace Accord\Core;

/** Sum of all increments. */
final class CounterState implements FieldState
{
    public int $total = 0;

    public function strategy(): Strategy
    {
        return Strategy::Counter;
    }

    public function apply(Op $op): void
    {
        if (!$op instanceof IncOp) {
            throw new AccordException("op kind \"{$op->kind()}\" does not apply to a counter field");
        }
        $this->total += $op->by;
    }

    public function read(): mixed
    {
        return $this->total;
    }

    public function observedDeps(string|int|float|null $element = null): array
    {
        return [];
    }

    public function snapshot(): JsonObject
    {
        return new JsonObject(['strategy' => 'counter', 'total' => $this->total]);
    }
}
