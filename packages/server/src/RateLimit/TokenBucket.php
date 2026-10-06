<?php

declare(strict_types=1);

namespace Accord\Server\RateLimit;

use Accord\Server\RateLimit;

/** The bucket arithmetic of the TypeScript `RateLimiter`, shared by every store. */
final class TokenBucket
{
    /**
     * Refills `$bucket` up to now, takes a token if there is one.
     *
     * @param ?array{tokens: float, at: int} $bucket null for a key never seen (starts full)
     *
     * @return array{0: int, 1: array{tokens: float, at: int}} [ms to wait (0: allowed), the new bucket]
     */
    public static function take(?array $bucket, RateLimit $limit, int $nowMs): array
    {
        $rate = $limit->perMinute / 60_000;
        $b = $bucket ?? ['tokens' => $limit->burst, 'at' => $nowMs];
        $b['tokens'] = min($limit->burst, $b['tokens'] + ($nowMs - $b['at']) * $rate);
        $b['at'] = $nowMs;
        if ($b['tokens'] >= 1) {
            $b['tokens'] -= 1;

            return [0, $b];
        }

        return [(int) ceil((1 - $b['tokens']) / $rate), $b];
    }

    /**
     * A bucket that has refilled completely behaves exactly like a new one: it can be dropped.
     *
     * @param array{tokens: float, at: int} $bucket
     */
    public static function full(array $bucket, RateLimit $limit, int $nowMs): bool
    {
        return $bucket['tokens'] + ($nowMs - $bucket['at']) * $limit->perMinute / 60_000 >= $limit->burst;
    }
}
