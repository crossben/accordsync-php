<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * Adds an element to a set; the op id is its tag. `deps` lists the element's tags the writer could
 * see: the add replaces them, while a concurrent remove still loses. Empty for a first add.
 */
final readonly class AddOp extends Op
{
    /** @param list<string> $deps */
    public function __construct(string $opId, string $record, string $field, Hlc $hlc, public string|int|float $element, public array $deps)
    {
        parent::__construct($opId, $record, $field, $hlc);
    }

    public function kind(): string
    {
        return 'add';
    }
}
