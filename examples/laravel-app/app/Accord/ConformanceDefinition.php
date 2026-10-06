<?php

declare(strict_types=1);

namespace App\Accord;

use Accord\Examples\ConformanceProfile;
use Accord\Server\ServerDefinition;

/** config('accord.definition'): the conformance profile, read from contract/conformance/profile.json. */
final class ConformanceDefinition
{
    public function __invoke(): ServerDefinition
    {
        return (new ConformanceProfile())->definition();
    }
}
