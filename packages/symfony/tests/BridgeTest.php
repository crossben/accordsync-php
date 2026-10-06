<?php

declare(strict_types=1);

namespace Accord\Symfony\Tests;

use Accord\Symfony\AccordBundle;
use PHPUnit\Framework\TestCase;

final class BridgeTest extends TestCase
{
    public function testTheEntryPointLoads(): void
    {
        self::assertTrue(class_exists(AccordBundle::class));
    }
}
