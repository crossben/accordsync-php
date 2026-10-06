<?php

declare(strict_types=1);

namespace Accord\Server;

use Accord\Core\LocalWriter;

/**
 * Sync request limits; the defaults are the TypeScript server's. There is no `maxConcurrentPushes`:
 * PHP serves one request per worker, so the worker pool size bounds concurrent pushes (ADR-P06).
 */
final readonly class Limits
{
    public function __construct(
        /** Request body size in bytes; larger requests get 413. */
        public int $maxBodyBytes = 5 * 1024 * 1024,
        /** Above this many records entering or leaving on a read-scope change, the device resyncs. */
        public int $maxScopeDelta = 2000,
        /** Ops per push request. */
        public int $maxPushOps = 500,
        /** Items per pull page. */
        public int $maxPullLimit = 1000,
        /** Ops whose clock is further ahead than this are refused. */
        public int $maxSkewMs = LocalWriter::DEFAULT_MAX_SKEW_MS,
    ) {
        if ($maxScopeDelta < 0) {
            throw new \InvalidArgumentException("limits: maxScopeDelta must be ≥ 0, got $maxScopeDelta");
        }
        foreach (['maxBodyBytes' => $maxBodyBytes, 'maxPushOps' => $maxPushOps, 'maxPullLimit' => $maxPullLimit, 'maxSkewMs' => $maxSkewMs] as $name => $v) {
            if ($v < 1) {
                throw new \InvalidArgumentException("limits: $name must be ≥ 1, got $v");
            }
        }
    }
}
