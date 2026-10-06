<?php

declare(strict_types=1);

namespace Accord\Laravel;

use Accord\Server\AccordServer;
use Accord\Server\CompactionResult;
use Accord\Server\Database;
use Accord\Server\Http\SyncHandler;
use Accord\Server\Migrator;
use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\ServerDefinition;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionResolverInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * The Accord server as Laravel sees it: the definition from `config/accord.php`, the PostgreSQL
 * connection, the rate-limit store, and the PSR-15 handler built from them. A singleton.
 */
final class Accord
{
    private ?ServerDefinition $definition = null;

    private ?\PDO $pdo = null;

    private ?RateLimiter $rateLimiter = null;

    public function __construct(private readonly Container $app, private readonly Config $config) {}

    public function definition(): ServerDefinition
    {
        return $this->definition ??= $this->resolveDefinition();
    }

    /** The PostgreSQL connection: ACCORD_DATABASE_URL, else a Laravel pgsql connection's PDO. */
    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        $url = $this->config->get('accord.database.url');
        if (\is_string($url) && $url !== '') {
            return $this->pdo = Database::connect($url, persistent: true);
        }
        $name = $this->config->get('accord.database.connection');
        /** @var ConnectionResolverInterface $db */
        $db = $this->app->make('db');
        $connection = $db->connection(\is_string($name) && $name !== '' ? $name : null);
        if (!$connection instanceof \Illuminate\Database\Connection || $connection->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('accord: the database connection must use the pgsql driver (or set ACCORD_DATABASE_URL)');
        }

        // The server's PDO settings (errors as exceptions, native prepares, assoc fetches): the server
        // applies them only to connections it opens or receives as a factory. Laravel sets its fetch
        // mode on each statement, so its queries are unaffected.
        return $this->pdo = Database::options($connection->getPdo());
    }

    public function rateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            $store = $this->config->get('accord.rate_limit.store');
            /** @var CacheFactory $cache */
            $cache = $this->app->make('cache');
            $this->rateLimiter = new CacheRateLimiter($cache->store(\is_string($store) && $store !== '' ? $store : null));
        }

        return $this->rateLimiter;
    }

    public function handler(): SyncHandler
    {
        $factory = new Psr17Factory();

        return AccordServer::handler($this->definition(), fn(): \PDO => $this->pdo(), $factory, $factory, $this->rateLimiter());
    }

    /** @return list<string> the migrations applied */
    public function migrate(): array
    {
        return (new Migrator($this->pdo()))->migrateToLatest();
    }

    public function compact(): CompactionResult
    {
        return AccordServer::compact($this->definition(), $this->pdo());
    }

    private function resolveDefinition(): ServerDefinition
    {
        $configured = $this->config->get('accord.definition');
        if ($configured instanceof ServerDefinition) {
            return $configured;
        }
        if (\is_string($configured) && $configured !== '' && class_exists($configured)) {
            $configured = $this->app->make($configured);
        }
        if (!\is_callable($configured)) {
            throw new \RuntimeException('accord: set accord.definition (ACCORD_DEFINITION) to an invokable class or a closure returning AccordServer::define(...)');
        }
        $definition = $this->app->call($configured);
        if (!$definition instanceof ServerDefinition) {
            throw new \RuntimeException('accord: accord.definition must return a ' . ServerDefinition::class . ', got ' . get_debug_type($definition));
        }

        return $definition;
    }
}
