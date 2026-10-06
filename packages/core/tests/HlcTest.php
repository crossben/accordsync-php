<?php

declare(strict_types=1);

namespace Accord\Core\Tests;

use Accord\Core\AccordException;
use Accord\Core\ClockSkewException;
use Accord\Core\Hlc;
use Accord\Core\Json;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/** Port of `hlc.test.ts`, with seeded random inputs in place of fast-check. */
final class HlcTest extends TestCase
{
    private Randomizer $rnd;

    protected function setUp(): void
    {
        $this->rnd = new Randomizer(new Mt19937(42));
    }

    private function randomHlc(?string $node = null): Hlc
    {
        return new Hlc(
            $this->rnd->getInt(0, (1 << 32) - 1) * 900 + $this->rnd->getInt(0, 899),
            $this->rnd->getInt(0, Hlc::MAX_COUNTER),
            $node ?? 'n' . $this->rnd->getInt(0, 999),
        );
    }

    public function testRoundTripsThroughItsWireEncoding(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $h = $this->randomHlc();
            self::assertEquals($h, Hlc::decode($h->encode()));
        }
    }

    public function testEncodesInTheDocumentedFormat(): void
    {
        self::assertSame('1727871000123:00004:dev-7f3a', (new Hlc(1727871000123, 4, 'dev-7f3a'))->encode());
    }

    public function testRejectsMalformedEncodings(): void
    {
        $bad = ['', '1:2', 'x:00001:a', '1:00001:', '-1:00001:a', '1:00001:a:b', "1:00001:a\n", '9999999999999999:00000:a'];
        foreach ($bad as $s) {
            try {
                Hlc::decode($s);
                self::fail("accepted \"$s\"");
            } catch (AccordException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOrdersTotallyAndAntisymmetrically(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $a = $this->randomHlc();
            $b = $i % 2 === 0 ? $this->randomHlc() : new Hlc($a->wall, $a->counter, 'z');
            self::assertSame(Hlc::compare($a, $b), -Hlc::compare($b, $a));
            if (Hlc::compare($a, $b) === 0) {
                self::assertTrue($a->equals($b));
            }
        }
        self::assertSame(-1, Hlc::compare(new Hlc(1, 0, 'B'), new Hlc(1, 0, 'a')));
    }

    public function testTickIsStrictlyIncreasingEvenWhenTheWallClockGoesBackwards(): void
    {
        $h = Hlc::initial('a');
        for ($i = 0; $i < 1000; $i++) {
            $next = $h->tick($this->rnd->getInt(0, 9999));
            self::assertSame(1, Hlc::compare($next, $h));
            $h = $next;
        }
    }

    public function testReceiveMovesPastBothTheLocalAndTheRemoteClock(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $local = $this->randomHlc('local');
            $remote = $this->randomHlc();
            $next = $local->receive($remote, $this->rnd->getInt(0, (1 << 32) - 1) * 900, Json::MAX_SAFE_INTEGER);
            self::assertSame(1, Hlc::compare($next, $local));
            self::assertTrue($next->wall > $remote->wall || ($next->wall === $remote->wall && $next->counter > $remote->counter));
            self::assertSame('local', $next->node);
        }
    }

    public function testAFullCounterRollsIntoTheNextMillisecondInsteadOfFailing(): void
    {
        self::assertEquals(new Hlc(6, 0, 'a'), (new Hlc(5, Hlc::MAX_COUNTER, 'a'))->tick(0));
        self::assertEquals(new Hlc(6, 0, 'a'), Hlc::initial('a')->receive(new Hlc(5, Hlc::MAX_COUNTER, 'b'), 0, 1000));
    }

    public function testRefusesARemoteClockTooFarInTheFuture(): void
    {
        $local = Hlc::initial('a');
        $remote = new Hlc(10000000, 0, 'b');
        $local->receive($remote, 9990000, 60000); // within the limit
        $this->expectException(ClockSkewException::class);
        $local->receive($remote, 1000, 60000);
    }

    public function testRefusesBadNodeIds(): void
    {
        $this->expectException(AccordException::class);
        Hlc::initial("a\n");
    }
}
