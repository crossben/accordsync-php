<?php

declare(strict_types=1);

namespace Accord\Symfony\Tests;

use Accord\Server\RateLimit;
use Accord\Server\ServerDefinition;
use Accord\Symfony\Accord;
use Accord\Symfony\CacheRateLimiter;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\RouterInterface;

final class BridgeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/accord-symfony-bridge-test');
    }

    /** @param array<string, mixed> $accord */
    private static function kernel(array $accord = [], int $definitions = 1): TestKernel
    {
        $kernel = new TestKernel($accord, $definitions);
        $kernel->boot();

        return $kernel;
    }

    public function testTheDefinitionComesFromTheTaggedService(): void
    {
        $accord = self::kernel()->getContainer()->get(Accord::class);
        self::assertInstanceOf(Accord::class, $accord);
        self::assertInstanceOf(ServerDefinition::class, $accord->definition());
    }

    public function testThePsr16CacheIsAutowiredForJwks(): void
    {
        $definition = self::kernel()->getContainer()->get('test.definition.0');
        self::assertInstanceOf(TestDefinition::class, $definition);
        self::assertInstanceOf(CacheInterface::class, $definition->accordCache);
    }

    public function testExactlyOneDefinitionIsRequired(): void
    {
        foreach ([0, 2] as $n) {
            try {
                self::kernel(definitions: $n);
                self::fail("$n definitions accepted");
            } catch (\LogicException $e) {
                self::assertStringContainsString("exactly one service must implement", $e->getMessage());
            }
        }
    }

    public function testRoutesAreUnderThePrefixAndStateless(): void
    {
        $router = self::kernel()->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach (['health' => '/health', 'v1.push' => '/v1/push', 'v1.pull' => '/v1/pull'] as $name => $path) {
            $route = $router->getRouteCollection()->get("accord.$name");
            self::assertNotNull($route);
            self::assertSame('/accord' . $path, $route->getPath());
            self::assertSame([], $route->getMethods());
            self::assertTrue($route->getDefault('_stateless'));
            self::assertSame($path, $route->getDefault('_accord_path'));
        }
    }

    public function testTheConfiguredPrefixIsUsed(): void
    {
        $router = self::kernel(['prefix' => '/sync/'])->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertSame('/sync/v1/push', $router->getRouteCollection()->get('accord.v1.push')?->getPath());
        $root = self::kernel(['prefix' => ''])->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $root);
        self::assertSame('/v1/push', $root->getRouteCollection()->get('accord.v1.push')?->getPath());
    }

    public function testRequestsReachTheHandlerWithItsHeadersAndErrors(): void
    {
        $response = self::kernel()->handle(Request::create('/accord/v1/pull?cursor=0', server: ['HTTP_ACCORD_DEVICE' => 'phone', 'QUERY_STRING' => 'cursor=0']));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Accord-Protocol'));
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('{"error":"missing bearer token"}', $response->getContent());
    }

    public function testAPostNeedsNoCsrfTokenOrSession(): void
    {
        $response = self::kernel()->handle(Request::create('/accord/v1/push', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"ops":[]}'));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $response->headers->getCookies());
    }

    public function testABasicAuthorizationHeaderIsA401NotA500(): void
    {
        $request = Request::create('/accord/v1/pull?cursor=0', server: ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode("\x01\x02:\x03"), 'HTTP_ACCORD_DEVICE' => 'phone']);
        self::assertSame(401, self::kernel()->handle($request)->getStatusCode());
    }

    public function testCommandsAreRegistered(): void
    {
        $app = new Application(self::kernel());
        self::assertTrue($app->has('accord:migrate'));
        self::assertTrue($app->has('accord:compact'));
    }

    public function testConfigurationDefaults(): void
    {
        $kernel = self::kernel();
        $accord = $kernel->getContainer()->get(Accord::class);
        self::assertInstanceOf(Accord::class, $accord);
        self::assertInstanceOf(CacheRateLimiter::class, $accord->rateLimiter());
        // No database.url and no Doctrine: a clear error, not a fatal.
        $this->expectExceptionMessage('ACCORD_DATABASE_URL');
        $accord->pdo();
    }

    public function testTheRateLimiter(): void
    {
        $limiter = new CacheRateLimiter(new ArrayAdapter(), new LockFactory(new InMemoryStore()));
        $limit = new RateLimit(60, burst: 2);
        self::assertSame(0, $limiter->take('device:a', $limit, 1_000));
        self::assertSame(0, $limiter->take('device:a', $limit, 1_000));
        self::assertSame(1_000, $limiter->take('device:a', $limit, 1_000));
        self::assertSame(0, $limiter->take('user:a@b{c}', $limit, 1_000), 'keys with PSR-6 reserved characters');
        self::assertSame(0, $limiter->take('device:a', $limit, 2_000), 'refilled one token per second');
        $limiter->clear();
        self::assertSame(0, $limiter->take('device:a', $limit, 2_000));
        self::assertSame(0, $limiter->take('device:a', $limit, 2_000));
    }
}
