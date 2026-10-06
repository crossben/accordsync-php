<?php

declare(strict_types=1);

namespace Accord\Laravel\Tests;

use Accord\Laravel\AccordServiceProvider;
use PHPUnit\Framework\TestCase;

final class BridgeTest extends TestCase
{
    public function testTheEntryPointLoads(): void
    {
        self::assertTrue(class_exists(AccordServiceProvider::class));
    }
}
