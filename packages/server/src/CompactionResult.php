<?php

declare(strict_types=1);

namespace Accord\Server;

/** What one compaction run did; serialises to the TypeScript `CompactionResult` shape. */
final readonly class CompactionResult implements \JsonSerializable
{
    public function __construct(
        /** Feed position every live device has applied: only ops at or below it were folded. */
        public int $watermark,
        public int $records,
        public int $opsFolded,
        /** Compacted-op entries forgotten because their devices have pushed past them. */
        public int $tombstonesPruned,
    ) {}

    /** @return array{watermark: int, records: int, opsFolded: int, tombstonesPruned: int} */
    public function jsonSerialize(): array
    {
        return ['watermark' => $this->watermark, 'records' => $this->records, 'opsFolded' => $this->opsFolded, 'tombstonesPruned' => $this->tombstonesPruned];
    }
}
