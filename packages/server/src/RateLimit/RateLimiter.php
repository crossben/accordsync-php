<?php

declare(strict_types=1);

namespace Accord\Server\RateLimit;

use Accord\Server\RateLimit;

/**
 * Token buckets for sync requests, one per key (a device, a user). PHP workers share no memory, so
 * the state must live where every worker sees it: the framework's cache in production (the Laravel
 * and Symfony bridges provide one), {@see FileRateLimiter} on one machine, {@see InMemoryRateLimiter}
 * for a single long-running process or tests.
 */
interface RateLimiter
{
    /** Takes one token for `$key`. Returns 0 if allowed, otherwise the milliseconds to wait. */
    public function take(string $key, RateLimit $limit, int $nowMs): int;

    /** Forgets every bucket (tests). */
    public function clear(): void;
}
