<?php

declare(strict_types=1);

namespace Accord\Server\RateLimit;

use Accord\Server\RateLimit;

/** Buckets in this PHP process only: right for a long-running server (RoadRunner, Swoole) or tests. */
final class InMemoryRateLimiter implements RateLimiter
{
    /** @var array<string, array{tokens: float, at: int, limit: RateLimit}> */
    private array $buckets = [];

    public function __construct(private readonly int $maxKeys = 100_000) {}

    public function take(string $key, RateLimit $limit, int $nowMs): int
    {
        $k = "\0" . $key;
        if (!isset($this->buckets[$k]) && \count($this->buckets) >= $this->maxKeys) {
            foreach ($this->buckets as $other => $b) {
                if (TokenBucket::full($b, $b['limit'], $nowMs)) {
                    unset($this->buckets[$other]);
                }
            }
        }
        $old = $this->buckets[$k] ?? null;
        [$wait, $b] = TokenBucket::take($old === null ? null : ['tokens' => $old['tokens'], 'at' => $old['at']], $limit, $nowMs);
        $this->buckets[$k] = $b + ['limit' => $limit];

        return $wait;
    }

    public function clear(): void
    {
        $this->buckets = [];
    }
}
