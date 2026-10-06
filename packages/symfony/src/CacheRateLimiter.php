<?php

declare(strict_types=1);

namespace Accord\Symfony;

use Accord\Server\RateLimit;
use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\RateLimit\TokenBucket;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Rate-limit token buckets in a Symfony cache pool, each read and written under a Symfony lock
 * (ADR-P07), with the same arithmetic as every other store ({@see TokenBucket}). The pool and the
 * lock store must be shared by every worker (and host). {@see clear()} bumps a generation number
 * instead of clearing the pool.
 */
final class CacheRateLimiter implements RateLimiter
{
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly LockFactory $locks,
        private readonly string $prefix = 'accord.rate.',
    ) {}

    public function take(string $key, RateLimit $limit, int $nowMs): int
    {
        // PSR-6 keys may not contain {}()/\@:
        $bucketKey = $this->prefix . $this->generation() . '.' . hash('xxh128', $key);
        $lock = $this->locks->createLock($bucketKey, 5);
        $lock->acquire(true);
        try {
            $item = $this->pool->getItem($bucketKey);
            $old = $item->isHit() ? $item->get() : null;
            $bucket = \is_array($old) && \is_numeric($old[0] ?? null) && \is_int($old[1] ?? null) ? ['tokens' => (float) $old[0], 'at' => $old[1]] : null;
            [$wait, $b] = TokenBucket::take($bucket, $limit, $nowMs);
            // A bucket left alone refills completely within burst / rate; keep it a minute longer.
            $this->pool->save($item->set([$b['tokens'], $b['at']])->expiresAfter((int) ceil($limit->burst / $limit->perMinute * 60) + 60));

            return $wait;
        } finally {
            $lock->release();
        }
    }

    public function clear(): void
    {
        $item = $this->pool->getItem($this->prefix . 'generation');
        $this->pool->save($item->set($this->generation() + 1));
    }

    private function generation(): int
    {
        $item = $this->pool->getItem($this->prefix . 'generation');
        $g = $item->isHit() ? $item->get() : 0;

        return \is_int($g) ? $g : 0;
    }
}
