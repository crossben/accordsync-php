<?php

declare(strict_types=1);

namespace Accord\Server;

/** The scope keys a user may read and write, from their verified JWT claims. */
final readonly class Access
{
    /**
     * @param list<string> $read
     * @param list<string> $write
     */
    public function __construct(public array $read, public array $write) {}
}
