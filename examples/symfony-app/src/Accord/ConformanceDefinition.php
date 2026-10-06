<?php

declare(strict_types=1);

namespace App\Accord;

use Accord\Examples\ConformanceProfile;
use Accord\Server\ServerDefinition;
use Accord\Symfony\DefinitionProvider;

/** The `accord.definition` service: the conformance profile, read from contract/conformance/profile.json. */
final class ConformanceDefinition implements DefinitionProvider
{
    public function define(): ServerDefinition
    {
        return (new ConformanceProfile())->definition();
    }
}
