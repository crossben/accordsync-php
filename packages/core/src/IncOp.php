<?php

declare(strict_types=1);

namespace Accord\Core;

/** Adds `by` (a positive or negative integer) to a counter. */
final readonly class IncOp extends Op
{
    public function __construct(string $opId, string $record, string $field, Hlc $hlc, public int $by)
    {
        parent::__construct($opId, $record, $field, $hlc);
    }

    public function kind(): string
    {
        return 'inc';
    }
}
