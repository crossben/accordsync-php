<?php

declare(strict_types=1);

namespace Accord\Server;

/** Who is calling: verified user, their device, and the scope keys their claims grant. */
final readonly class Caller
{
    /**
     * @param list<string> $read
     * @param list<string> $write
     */
    public function __construct(
        public string $sub,
        public string $deviceId,
        public array $read,
        public array $write,
    ) {}
}
