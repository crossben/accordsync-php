<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Server\RateLimit;
use Accord\Server\RateLimit\FileRateLimiter;
use Accord\Server\RateLimit\InMemoryRateLimiter;
use Accord\Server\RateLimit\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private static function exercise(RateLimiter $a, RateLimiter $b): void
    {
        $limit = new RateLimit(60, burst: 2); // one token per second
        self::assertSame(0, $a->take('k', $limit, 1000));
        self::assertSame(0, $b->take('k', $limit, 1000));
        self::assertSame(1000, $a->take('k', $limit, 1000));
        self::assertSame(500, $b->take('k', $limit, 1500));
        self::assertSame(0, $a->take('k', $limit, 2000));
        self::assertSame(0, $a->take('other', $limit, 2000), 'keys are independent');
        $a->clear();
        self::assertSame(0, $b->take('k', $limit, 2000));
        self::assertSame(0, $b->take('k', $limit, 2000));
    }

    public function testInMemory(): void
    {
        $m = new InMemoryRateLimiter();
        self::exercise($m, $m);
    }

    public function testFileStoreIsSharedBetweenInstances(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'accord-rate-test');
        self::assertIsString($path);
        try {
            self::exercise(new FileRateLimiter($path), new FileRateLimiter($path));
        } finally {
            @unlink($path);
        }
    }

    public function testInMemoryEvictsFullBuckets(): void
    {
        $m = new InMemoryRateLimiter(maxKeys: 2);
        $limit = new RateLimit(60, burst: 1);
        self::assertSame(0, $m->take('a', $limit, 0));
        self::assertSame(0, $m->take('b', $limit, 0));
        self::assertSame(0, $m->take('c', $limit, 5000)); // a and b refilled: evicted
        self::assertSame(1000, $m->take('c', $limit, 5000));
    }
}
