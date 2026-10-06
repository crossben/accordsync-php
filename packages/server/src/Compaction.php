<?php

declare(strict_types=1);

namespace Accord\Server;

/** Log compaction settings (ADR-0005, ADR-0008). */
final readonly class Compaction
{
    public const int DAY_MS = 24 * 3_600_000;

    public function __construct(
        /** A device unseen this long is retired and no longer holds compaction back. */
        public float $deviceTtlDays = 30,
        /** How often a scheduler should compact; 0 disables it. The handler itself never compacts. */
        public int $intervalMs = 3_600_000,
        /** Only records with at least this many ops are compacted. */
        public int $minOps = 20,
    ) {}

    public function deviceTtlMs(): float
    {
        return $this->deviceTtlDays * self::DAY_MS;
    }
}
