<?php

declare(strict_types=1);

namespace Accord\Core\Tests;

use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Core\Wire;
use Accord\Testing\Contract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Random scenarios written by the TypeScript core (`random-vectors.test.ts`), with the snapshots it
 * read. This core must produce the same bytes: same merge, same canonical JSON (key order, number
 * and string formatting), in any delivery order, and the same compacted record snapshots.
 */
final class RandomVectorsTest extends TestCase
{
    private static ?JsonObject $file = null;

    private static function file(): JsonObject
    {
        return self::$file ??= Support::readJson(Contract::dir() . '/vectors/random/cases.json');
    }

    /** @return list<mixed> */
    private static function all(): array
    {
        $cases = self::file()->get('cases');
        self::assertIsArray($cases);

        return array_values($cases);
    }

    public function testThereAreCasesToCheck(): void
    {
        self::assertGreaterThanOrEqual(40, \count(self::all()));
    }

    /** @return iterable<string, array{int}> */
    public static function cases(): iterable
    {
        foreach (self::all() as $i => $c) {
            self::assertInstanceOf(JsonObject::class, $c);
            yield 'seed ' . Json::canonical($c->get('seed')) => [$i];
        }
    }

    #[DataProvider('cases')]
    public function testReproducesTheTypeScriptSnapshot(int $i): void
    {
        $schema = Support::schemaFromJson(self::file()->get('schema'));
        $c = self::all()[$i];
        self::assertInstanceOf(JsonObject::class, $c);
        $ops = Support::decodeOps($c->get('ops'));
        $seed = $c->get('seed');
        self::assertIsInt($seed);
        $rnd = new Randomizer(new Mt19937($seed));
        for ($k = 0; $k < 5; $k++) {
            $order = $ops;
            if ($k > 0) {
                $order = $rnd->shuffleArray([...$ops, ...\array_slice($ops, 0, $rnd->getInt(0, 4))]);
            }
            $r = Support::replay($schema, $order);
            self::assertSame($c->get('snapshot'), $r->snapshot(), "order $k");
            if ($k === 0) {
                $records = $c->get('records');
                self::assertInstanceOf(JsonObject::class, $records);
                $expectedIds = $records->keys();
                usort($expectedIds, \Accord\Core\Utf16::compare(...));
                self::assertSame($expectedIds, $r->records());
                foreach ($records as $record => $expected) {
                    self::assertSame($expected, Json::canonical($r->snapshotRecord($record)->toJson()), $record);
                }
            }
        }
        // Wire round trip: re-encoding gives the ops back unchanged.
        self::assertSame(Json::canonical($c->get('ops')), Json::canonical(array_map(Wire::encode(...), $ops)));
    }
}
