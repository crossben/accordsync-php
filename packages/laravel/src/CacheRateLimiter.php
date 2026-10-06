<?php

declare(strict_types=1);

namespace Accord\Laravel;

use Accord\Server\RateLimit;
use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\RateLimit\TokenBucket;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Rate-limit token buckets in a Laravel cache store, shared by every worker that shares the store
 * (ADR-P07). Each bucket is read and written under the store's atomic lock, with the same arithmetic
 * as every other store ({@see TokenBucket}). {@see clear()} bumps a generation number instead of
 * deleting keys, so it never flushes the rest of the cache.
 */
final class CacheRateLimiter implements RateLimiter
{
    public function __construct(private readonly Repository $cache, private readonly string $prefix = 'accord:rate:')
    {
        if (!$cache->getStore() instanceof LockProvider) {
            throw new \InvalidArgumentException('accord: the rate-limit cache store must support atomic locks (redis, memcached, database, file, array)');
        }
    }

    public function take(string $key, RateLimit $limit, int $nowMs): int
    {
        $store = $this->cache->getStore();
        \assert($store instanceof LockProvider);
        $bucketKey = $this->prefix . $this->generation() . ':' . $key;
        $lock = $store->lock($bucketKey . ':lock', 5);
        if (method_exists($lock, 'betweenBlockedAttemptsSleepFor')) {
            $lock->betweenBlockedAttemptsSleepFor(2);
        }

        /** @var int */
        return $lock->block(5, function () use ($bucketKey, $limit, $nowMs): int {
            $old = $this->cache->get($bucketKey);
            $bucket = \is_array($old) && \is_numeric($old[0] ?? null) && \is_int($old[1] ?? null) ? ['tokens' => (float) $old[0], 'at' => $old[1]] : null;
            [$wait, $b] = TokenBucket::take($bucket, $limit, $nowMs);
            // A bucket left alone refills completely within burst / rate; keep it a minute longer.
            $this->cache->put($bucketKey, [$b['tokens'], $b['at']], (int) ceil($limit->burst / $limit->perMinute * 60) + 60);

            return $wait;
        });
    }

    public function clear(): void
    {
        $this->cache->forever($this->prefix . 'generation', $this->generation() + 1);
    }

    private function generation(): int
    {
        $g = $this->cache->get($this->prefix . 'generation');

        return \is_int($g) ? $g : 0;
    }
}
