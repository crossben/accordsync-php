<?php

declare(strict_types=1);

namespace Accord\Server;

/** Sync request limits per device and per user (429 with Retry-After when exceeded). */
final readonly class RateLimits
{
    public RateLimit $perDevice;

    public RateLimit $perUser;

    public function __construct(?RateLimit $perDevice = null, ?RateLimit $perUser = null)
    {
        $this->perDevice = $perDevice ?? new RateLimit(600);
        $this->perUser = $perUser ?? new RateLimit(1800);
    }
}
