<?php

declare(strict_types=1);

namespace Accord\Core\Tests;

use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Testing\Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The golden vectors shared with `@accordsync/core`: every case, in every delivery order, each op
 * delivered twice, must read exactly the expected canonical snapshot.
 */
final class VectorsTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function cases(): iterable
    {
        foreach (Contract::vectorFiles() as $file) {
            $cases = Support::readJson($file)->get('cases');
            self::assertIsArray($cases);
            foreach ($cases as $i => $c) {
                self::assertInstanceOf(JsonObject::class, $c);
                yield basename($file) . ': ' . (string) $c->get('name') => [$file, $i];
            }
        }
    }

    #[DataProvider('cases')]
    public function testEveryOrderReadsTheExpectedSnapshot(string $file, int $i): void
    {
        $v = Support::readJson($file);
        self::assertSame(1, $v->get('version'));
        $schema = Support::schemaFromJson($v->get('schema'));
        $cases = $v->get('cases');
        self::assertIsArray($cases);
        $c = $cases[$i];
        self::assertInstanceOf(JsonObject::class, $c);
        $ops = Support::decodeOps($c->get('ops'));
        $expected = Json::canonical($c->get('expected'));
        $orders = 0;
        foreach (Support::permutations($ops) as $order) {
            $r = Support::replay($schema, [...$order, ...$order]);
            self::assertSame($expected, $r->snapshot(), implode(' ', array_map(static fn($o) => $o->opId, $order)));
            $orders++;
        }
        self::assertGreaterThan(0, $orders);
    }
}
