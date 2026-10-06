<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Server\AccordServer;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    public function testSpeaksProtocolVersion1(): void
    {
        self::assertSame(1, AccordServer::PROTOCOL_VERSION);
    }
}
