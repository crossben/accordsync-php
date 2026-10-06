<?php

declare(strict_types=1);

namespace Accord\Core\Tests;

use Accord\Core\Protocol;
use Accord\Testing\Contract;
use PHPUnit\Framework\TestCase;

/** The contract is there and readable; the vector tests (VectorsTest, RandomVectorsTest) use it. */
final class ContractTest extends TestCase
{
    public function testSpeaksProtocolVersion1(): void
    {
        self::assertSame(1, Protocol::VERSION);
    }

    public function testGoldenVectorsArePresent(): void
    {
        $names = array_map('basename', Contract::vectorFiles());
        self::assertSame(['conflict.json', 'counter.json', 'lww.json', 'set.json'], $names);
        foreach (Contract::vectorFiles() as $file) {
            $v = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $v->version, $file);
        }
        self::assertFileExists(Contract::dir() . '/vectors/random/cases.json');
    }

    public function testProtocolSchemasArePresent(): void
    {
        foreach (['WireOp', 'PushRequest', 'PushResponse', 'PullItem', 'PullResponse'] as $name) {
            self::assertFileExists(Contract::dir() . "/protocol/v1/$name.schema.json");
        }
    }
}
