<?php

declare(strict_types=1);

namespace Accord\Server;

/** 429 Too Many Requests, with how long to wait. */
final class TooManyRequests extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterMs)
    {
        parent::__construct('too many requests');
    }
}
