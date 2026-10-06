<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * Marks a field with no value (never written): left out of reads and snapshots, like JavaScript's
 * `undefined`. A field written with `null` reads as `null`.
 */
enum Absent
{
    case Value;
}
