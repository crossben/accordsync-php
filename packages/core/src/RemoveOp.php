<?php

declare(strict_types=1);

namespace Accord\Core;

/** Removes the add tags in `deps` (the ones the writer had seen); concurrent adds survive. */
final readonly class RemoveOp extends Op
{
    /** @param list<string> $deps */
    public function __construct(string $opId, string $record, string $field, Hlc $hlc, public string|int|float $element, public array $deps)
    {
        parent::__construct($opId, $record, $field, $hlc);
    }

    public function kind(): string
    {
        return 'remove';
    }
}
