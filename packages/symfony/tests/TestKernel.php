<?php

declare(strict_types=1);

namespace Accord\Symfony\Tests;

use Accord\Symfony\AccordBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/** A minimal app: FrameworkBundle, AccordBundle, and the definitions it is given. */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** @param array<string, mixed> $accord the `accord:` configuration */
    public function __construct(private readonly array $accord = [], private readonly int $definitions = 1)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new AccordBundle()];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/accord-symfony-bridge-test/' . md5(serialize([$this->accord, $this->definitions])) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/accord-symfony-bridge-test/log';
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false, 'handle_all_throwables' => true, 'php_errors' => ['log' => true], 'session' => false]);
        $container->extension('accord', $this->accord);
        for ($i = 0; $i < $this->definitions; $i++) {
            $container->services()->set("test.definition.$i", TestDefinition::class)->autowire()->autoconfigure()->public();
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'accord');
    }
}
