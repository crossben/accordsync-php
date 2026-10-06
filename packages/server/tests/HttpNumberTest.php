<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Server\Sync;
use PHPUnit\Framework\TestCase;

/** `?cursor=` and `?limit=` are read with JavaScript's `Number()`, as the TypeScript server does. */
final class HttpNumberTest extends TestCase
{
    public function testJsNumber(): void
    {
        foreach (['' => 0.0, ' 12 ' => 12.0, '0x10' => 16.0, '1e3' => 1000.0, '-1' => -1.0, '1.5' => 1.5, '.5' => 0.5, '5.' => 5.0, 'Infinity' => INF, '+7' => 7.0] as $s => $n) {
            self::assertSame($n, Sync::jsNumber((string) $s), "Number('$s')");
        }
        foreach (['abc', '1,5', '0x', '1e', 'infinity', '12px', '--1'] as $s) {
            self::assertNull(Sync::jsNumber($s), "Number('$s')");
        }
    }

    public function testSafeInteger(): void
    {
        self::assertTrue(Sync::isSafeInteger(9007199254740991.0));
        self::assertFalse(Sync::isSafeInteger(9007199254740992.0));
        self::assertFalse(Sync::isSafeInteger(1.5));
        self::assertFalse(Sync::isSafeInteger(INF));
    }
}
