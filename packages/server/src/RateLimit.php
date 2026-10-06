<?php

declare(strict_types=1);

namespace Accord\Server;

/** A token bucket: `burst` requests at once, refilled at `perMinute`. */
final readonly class RateLimit
{
    public float $burst;

    public function __construct(public float $perMinute, ?float $burst = null)
    {
        if (!($perMinute > 0)) {
            throw new \InvalidArgumentException('rate limit perMinute must be > 0');
        }
        $this->burst = $burst ?? $perMinute;
    }
}
