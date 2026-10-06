<?php

declare(strict_types=1);

namespace Accord\Laravel\Tests;

use Accord\Laravel\Accord;
use Accord\Laravel\AccordServiceProvider;
use Accord\Laravel\CacheRateLimiter;
use Accord\Server\RateLimit;
use Accord\Server\ServerDefinition;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Routing\Route;
use Orchestra\Testbench\TestCase;

final class BridgeTest extends TestCase
{
    /** @var array<string, mixed> config set before the app boots (see refreshApplicationWith) */
    private array $config = [];

    protected function getPackageProviders($app): array
    {
        return [AccordServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('accord.definition', Fixtures::class);
        $app['config']->set('cache.default', 'array');
        foreach ($this->config as $k => $v) {
            $app['config']->set($k, $v);
        }
    }

    public function testConfigDefaultsAreMerged(): void
    {
        self::assertSame('accord', config('accord.prefix'));
        self::assertSame([], config('accord.middleware'));
        self::assertTrue((bool) config('accord.schedule'));
        self::assertArrayHasKey('url', (array) config('accord.database'));
    }

    public function testTheProviderIsAutoDiscovered(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        self::assertIsArray($composer);
        self::assertSame([AccordServiceProvider::class], $composer['extra']['laravel']['providers']);
    }

    public function testRoutesAreUnderThePrefixAndOutsideTheWebGroup(): void
    {
        foreach (['health' => '/health', 'v1.push' => '/v1/push', 'v1.pull' => '/v1/pull'] as $name => $path) {
            $route = app('router')->getRoutes()->getByName("accord.$name");
            self::assertInstanceOf(Route::class, $route);
            self::assertSame('accord' . $path, $route->uri());
            self::assertSame($path, $route->defaults['accord_path']);
            self::assertContains('POST', $route->methods());
            self::assertContains('OPTIONS', $route->methods());
            // No session, cookies or CSRF: the routes carry no middleware group at all.
            self::assertSame([], $route->gatherMiddleware());
        }
    }

    public function testAnEmptyPrefixServesAtTheRoot(): void
    {
        $this->refreshApplicationWith(['accord.prefix' => '']);
        self::assertSame('v1/push', app('router')->getRoutes()->getByName('accord.v1.push')?->uri());
    }

    public function testRequestsReachTheHandlerWithItsHeadersAndErrors(): void
    {
        $r = $this->get('/accord/v1/pull?cursor=0', ['Accord-Device' => 'phone']);
        $r->assertStatus(401);
        $r->assertHeader('Accord-Protocol', '1');
        self::assertSame('application/json', $r->headers->get('Content-Type'));
        self::assertSame(['error' => 'missing bearer token'], $r->json());
    }

    public function testAPostWithoutCsrfTokenOrSessionIsNotRejectedByTheFramework(): void
    {
        $r = $this->call('POST', '/accord/v1/push', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"ops":[]}');
        $r->assertStatus(401); // the handler's answer, not Laravel's 419
        self::assertNull($r->headers->get('Set-Cookie'));
    }

    public function testABasicAuthorizationHeaderIsA401NotA500(): void
    {
        $r = $this->get('/accord/v1/pull?cursor=0', ['Authorization' => 'Basic ' . base64_encode("\x01\x02:\x03"), 'Accord-Device' => 'phone']);
        $r->assertStatus(401);
    }

    public function testUnknownPathsUnderThePrefixAreNotServed(): void
    {
        $this->get('/accord/v1/other')->assertStatus(404);
    }

    public function testTheDefinitionResolvesFromAClassName(): void
    {
        self::assertInstanceOf(ServerDefinition::class, app(Accord::class)->definition());
    }

    public function testTheDefinitionResolvesFromAClosureWithInjection(): void
    {
        $seen = null;
        $this->refreshApplicationWith(['accord.definition' => static function (CacheContract $cache) use (&$seen): ServerDefinition {
            $seen = $cache;

            return Fixtures::definition();
        }]);
        app(Accord::class)->definition();
        self::assertInstanceOf(CacheContract::class, $seen);
    }

    public function testAMissingDefinitionIsAClearError(): void
    {
        $this->refreshApplicationWith(['accord.definition' => null]);
        $this->expectExceptionMessage('set accord.definition');
        app(Accord::class)->definition();
    }

    public function testADefinitionReturningSomethingElseIsAClearError(): void
    {
        $this->refreshApplicationWith(['accord.definition' => static fn(): string => 'nope']);
        $this->expectExceptionMessage('must return');
        app(Accord::class)->definition();
    }

    public function testANonPgsqlConnectionIsRefused(): void
    {
        $this->refreshApplicationWith(['database.default' => 'testing', 'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $this->expectExceptionMessage('pgsql');
        app(Accord::class)->pdo();
    }

    public function testCommandsAreRegistered(): void
    {
        $commands = app(ConsoleKernel::class)->all();
        self::assertArrayHasKey('accord:migrate', $commands);
        self::assertArrayHasKey('accord:compact', $commands);
    }

    public function testCompactionIsScheduledFromTheDefinitionInterval(): void
    {
        $this->refreshApplicationWith(['accord.definition' => static fn(): ServerDefinition => Fixtures::definition(compactEveryMs: 15 * 60_000)]);
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn(Event $e): bool => str_contains((string) $e->command, 'accord:compact')));
        self::assertCount(1, $events);
        self::assertSame('*/15 * * * *', $events[0]->expression);
    }

    public function testNoScheduleWhenTheIntervalIsZeroOrSchedulingIsOff(): void
    {
        $count = static fn(): int => \count(array_filter(app(Schedule::class)->events(), static fn(Event $e): bool => str_contains((string) $e->command, 'accord:compact')));
        self::assertSame(0, $count()); // Fixtures: intervalMs 0
        $this->refreshApplicationWith(['accord.schedule' => false, 'accord.definition' => static fn(): ServerDefinition => Fixtures::definition(compactEveryMs: 3_600_000)]);
        self::assertSame(0, $count());
    }

    public function testCronExpressions(): void
    {
        self::assertNull(AccordServiceProvider::cron(0));
        self::assertSame('* * * * *', AccordServiceProvider::cron(1_000));
        self::assertSame('*/5 * * * *', AccordServiceProvider::cron(300_000));
        self::assertSame('0 * * * *', AccordServiceProvider::cron(3_600_000));
        self::assertSame('0 */6 * * *', AccordServiceProvider::cron(6 * 3_600_000));
        self::assertSame('0 0 * * *', AccordServiceProvider::cron(48 * 3_600_000));
    }

    public function testTheRateLimiterUsesTheConfiguredCacheStore(): void
    {
        $limiter = app(Accord::class)->rateLimiter();
        self::assertInstanceOf(CacheRateLimiter::class, $limiter);
        $limit = new RateLimit(60, burst: 2);
        self::assertSame(0, $limiter->take('device:a', $limit, 1_000));
        self::assertSame(0, $limiter->take('device:a', $limit, 1_000));
        self::assertSame(1_000, $limiter->take('device:a', $limit, 1_000));
        self::assertSame(0, $limiter->take('device:b', $limit, 1_000));
        self::assertSame(0, $limiter->take('device:a', $limit, 2_000), 'refilled one token per second');
        $limiter->clear();
        self::assertSame(0, $limiter->take('device:a', $limit, 2_000));
        self::assertSame(0, $limiter->take('device:a', $limit, 2_000));
    }

    public function testTheRateLimiterNeedsAStoreWithLocks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CacheRateLimiter(new Repository($this->createMock(Store::class)));
    }

    /** @param array<string, mixed> $config */
    private function refreshApplicationWith(array $config): void
    {
        $this->config = $config;
        $this->refreshApplication();
    }
}
