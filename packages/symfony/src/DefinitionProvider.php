<?php

declare(strict_types=1);

namespace Accord\Symfony;

use Accord\Server\ServerDefinition;

/**
 * The app's Accord server definition. Implement it in one service: it is autoconfigured with the
 * `accord.definition` tag, and the bundle uses the single tagged service (ADR-P09).
 */
interface DefinitionProvider
{
    public const string TAG = 'accord.definition';

    public function define(): ServerDefinition;
}
