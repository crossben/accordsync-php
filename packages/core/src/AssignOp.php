<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * Writes a value. For `lww` the highest clock wins; for `conflict`, `deps` lists the values the
 * writer could see, and the assign supersedes exactly those (ADR-0003).
 */
final readonly class AssignOp extends Op
{
    /** @param list<string> $deps */
    public function __construct(string $opId, string $record, string $field, Hlc $hlc, public mixed $value, public array $deps)
    {
        parent::__construct($opId, $record, $field, $hlc);
    }

    public function kind(): string
    {
        return 'assign';
    }
}
