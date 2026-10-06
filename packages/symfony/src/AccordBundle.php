<?php

declare(strict_types=1);

namespace Accord\Symfony;

use Accord\Symfony\Command\CompactCommand;
use Accord\Symfony\Command\MigrateCommand;
use Accord\Symfony\Controller\SyncController;
use Accord\Symfony\DependencyInjection\DefinitionPass;
use Accord\Symfony\Routing\AccordRouteLoader;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Wires the Accord server into Symfony: configuration (`accord:` in config/packages), the sync routes
 * (route type `accord`), the `bin/console accord:migrate` / `accord:compact` commands, the Doctrine
 * DBAL connection, and rate limits in a cache pool. All sync behaviour lives in accordsync/server.
 */
final class AccordBundle extends AbstractBundle
{
    protected string $extensionAlias = 'accord';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('prefix')->defaultValue('accord')->info('The sync API is served under /{prefix}; clients use https://your-app/{prefix} as their server URL.')->end()
                ->arrayNode('database')->addDefaultsIfNotSet()->children()
                    ->scalarNode('url')->defaultNull()->info('postgres://user:password@host:5432/database; when empty, the Doctrine DBAL connection below is used.')->end()
                    ->scalarNode('connection')->defaultValue('default')->info('The Doctrine DBAL connection name (pdo_pgsql).')->end()
                ->end()->end()
                ->arrayNode('rate_limit')->addDefaultsIfNotSet()->children()
                    ->scalarNode('cache_pool')->defaultValue('cache.app')->info('A PSR-6 cache pool shared by every worker (and host).')->end()
                    ->scalarNode('lock_factory')->defaultNull()->info('A LockFactory service shared by every worker (and host); null: flock on this host.')->end()
                ->end()->end()
                ->scalarNode('jwks_cache_pool')->defaultValue('cache.app')->info('The cache pool behind the PSR-16 cache for Auth::jwks(..., cache: ...).')->end()
            ->end();
    }

    /** @param array{prefix: string, database: array{url: ?string, connection: string}, rate_limit: array{cache_pool: string, lock_factory: ?string}, jwks_cache_pool: string} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(DefinitionProvider::class)->addTag(DefinitionProvider::TAG);
        $services = $container->services();

        $locks = $config['rate_limit']['lock_factory'];
        if ($locks === null) {
            $services->set('accord.rate_limit.lock_store', FlockStore::class);
            $services->set('accord.rate_limit.lock_factory', LockFactory::class)->args([new Reference('accord.rate_limit.lock_store')]);
            $locks = 'accord.rate_limit.lock_factory';
        }
        $services->set('accord.rate_limiter', CacheRateLimiter::class)
            ->args([new Reference($config['rate_limit']['cache_pool']), new Reference($locks)]);

        $services->set(Accord::class)->public()->args([
            '$provider' => null, // set by DefinitionPass
            '$rateLimiter' => new Reference('accord.rate_limiter'),
            '$databaseUrl' => $config['database']['url'],
            '$dbal' => new Reference("doctrine.dbal.{$config['database']['connection']}_connection", ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);

        $services->set('accord.jwks_cache', Psr16Cache::class)->args([new Reference($config['jwks_cache_pool'])]);
        $services->alias(CacheInterface::class . ' $accordCache', 'accord.jwks_cache');

        $services->set(SyncController::class)->args([new Reference(Accord::class)])->public()->tag('controller.service_arguments');
        $services->set(AccordRouteLoader::class)->args([$config['prefix']])->tag('routing.loader');
        $services->set(MigrateCommand::class)->args([new Reference(Accord::class)])->tag('console.command');
        $services->set(CompactCommand::class)->args([new Reference(Accord::class)])->tag('console.command');
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DefinitionPass());
    }
}
