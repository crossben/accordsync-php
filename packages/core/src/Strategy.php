<?php

declare(strict_types=1);

namespace Accord\Core;

/** How a field merges concurrent writes. */
enum Strategy: string
{
    /** Highest clock wins. For names, notes and simple scalars. */
    case Lww = 'lww';

    /** Sum of all increments; none is ever lost. For quantities and stock adjustments. */
    case Counter = 'counter';

    /** Add-wins set of strings or numbers. For tags and assigned agents. */
    case Set = 'set';

    /** Concurrent values are all kept and the field is flagged; the app resolves it. Never guesses. */
    case Conflict = 'conflict';

    /**
     * The op kinds this strategy accepts.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::Lww, self::Conflict => ['assign'],
            self::Counter => ['inc'],
            self::Set => ['add', 'remove'],
        };
    }
}
