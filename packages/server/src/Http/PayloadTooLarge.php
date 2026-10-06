<?php

declare(strict_types=1);

namespace Accord\Server\Http;

/** 413: the request body is larger than `maxBodyBytes`. */
final class PayloadTooLarge extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('request body too large');
    }
}
