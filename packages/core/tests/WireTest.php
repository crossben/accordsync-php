<?php

declare(strict_types=1);

namespace Accord\Core\Tests;

use Accord\Core\AccordException;
use Accord\Core\AddOp;
use Accord\Core\AssignOp;
use Accord\Core\Hlc;
use Accord\Core\IncOp;
use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Core\Op;
use Accord\Core\Wire;
use PHPUnit\Framework\TestCase;

/** Port of `wire.test.ts`. */
final class WireTest extends TestCase
{
    private static function op(): AssignOp
    {
        return new AssignOp('dev-7f3a:1042', 'dossier:91', 'status', new Hlc(1727871000123, 4, 'dev-7f3a'), 'submitted', ['dev-7f3a:1041']);
    }

    /** @param array<string, mixed> $changes */
    private static function with(JsonObject $o, array $changes, ?string $drop = null): JsonObject
    {
        $copy = new JsonObject($o);
        foreach ($changes as $k => $v) {
            $copy->set($k, $v);
        }
        if ($drop !== null) {
            $copy->remove($drop);
        }

        return $copy;
    }

    public function testRoundTripsAnOp(): void
    {
        $json = Json::canonical(Wire::encode(self::op()));
        self::assertSame($json, Json::canonical(Wire::encode(Wire::decode(Json::decode($json)))));
    }

    public function testMatchesTheDocumentedShape(): void
    {
        self::assertSame(
            '{"deps":["dev-7f3a:1041"],"field":"status","hlc":"1727871000123:00004:dev-7f3a","kind":"assign","op_id":"dev-7f3a:1042","record":"dossier:91","value":"submitted"}',
            Json::canonical(Wire::encode(self::op())),
        );
    }

    public function testAFirstAddLeavesDepsOutLikeTheTypeScriptEncoder(): void
    {
        $add = new AddOp('a:1', 'dossier:1', 'docs', new Hlc(1, 0, 'a'), 'x', []);
        self::assertFalse(Wire::encode($add)->has('deps'));
        $back = Wire::decode(Wire::encode($add));
        self::assertInstanceOf(AddOp::class, $back);
        self::assertSame([], $back->deps);
    }

    public function testAcceptsAWholeNumberSentAsAFloatForInc(): void
    {
        $op = Wire::decode(self::with(Wire::encode(self::op()), ['kind' => 'inc', 'by' => 3.0]));
        self::assertInstanceOf(IncOp::class, $op);
        self::assertSame(3, $op->by);
        $op = Wire::decode(Json::decode('{"op_id":"a:1","record":"r:1","field":"f","hlc":"1:00000:a","kind":"inc","by":-2e0}'));
        self::assertInstanceOf(IncOp::class, $op);
        self::assertSame(-2, $op->by);
    }

    public function testAssignNullIsAValue(): void
    {
        $op = Wire::decode(self::with(Wire::encode(self::op()), ['value' => null]));
        self::assertInstanceOf(AssignOp::class, $op);
        self::assertNull($op->value);
    }

    public function testKeepsObjectValuesApartFromArrays(): void
    {
        $json = '{"op_id":"a:1","record":"r:1","field":"f","hlc":"1:00000:a","kind":"assign","value":{"10":{},"9":[]},"deps":[]}';
        $op = Wire::decode(Json::decode($json));
        self::assertInstanceOf(AssignOp::class, $op);
        self::assertSame('{"9":[],"10":{}}', Json::canonical($op->value));
    }

    public function testRejectsMalformedOpsWithAReason(): void
    {
        $good = Wire::encode(self::op());
        $bad = [
            null,
            [$good],
            new JsonObject(),
            self::with($good, ['op_id' => 'no-seq']),
            self::with($good, ['op_id' => 'other:1']), // op id device must match the clock's node
            self::with($good, ['op_id' => 'dev-7f3a:01']),
            self::with($good, ['op_id' => "dev-7f3a:1\n"]),
            self::with($good, ['record' => 'no-type']),
            self::with($good, ['record' => 'dossier:' . str_repeat('x', 257)]),
            self::with($good, ['kind' => 'explode']),
            self::with($good, ['kind' => 'inc', 'by' => 1.5]),
            self::with($good, ['kind' => 'inc', 'by' => '1']),
            self::with($good, ['kind' => 'inc', 'by' => 2 ** 53]),
            self::with($good, ['kind' => 'inc', 'by' => 1e300]),
            self::with($good, ['kind' => 'add', 'element' => []]),
            self::with($good, ['kind' => 'add', 'element' => NAN]),
            self::with($good, ['kind' => 'add', 'element' => null]),
            self::with($good, ['deps' => 'a:1']),
            self::with($good, ['deps' => [1]]),
            self::with($good, ['deps' => new JsonObject(['0' => 'a:1'])]),
            self::with($good, ['hlc' => 'garbage']),
            self::with($good, [], 'value'),
            self::with($good, ['kind' => 'remove', 'element' => 'x'], 'deps'),
        ];
        foreach ($bad as $i => $b) {
            try {
                Wire::decode($b);
                self::fail("accepted bad op #$i");
            } catch (AccordException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRecordIdLengthCountsUtf16CodeUnits(): void
    {
        // 128 emoji are 256 code units (fine); 129 are 258.
        self::assertSame('t', Op::recordType('t:' . str_repeat('😀', 128)));
        $this->expectException(AccordException::class);
        Op::recordType('t:' . str_repeat('😀', 129));
    }
}
