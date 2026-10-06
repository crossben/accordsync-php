<?php

declare(strict_types=1);

namespace Accord\Symfony;

use Accord\Server\AccordServer;
use Accord\Server\CompactionResult;
use Accord\Server\Database;
use Accord\Server\Http\SyncHandler;
use Accord\Server\Migrator;
use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\ServerDefinition;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * The Accord server as Symfony sees it: the tagged definition, the PostgreSQL connection, the
 * rate-limit store, and the PSR-15 handler built from them (service `Accord\Symfony\Accord`).
 */
final class Accord
{
    private ?ServerDefinition $definition = null;

    private ?\PDO $pdo = null;

    /** @param ?object $dbal a Doctrine DBAL connection, used when `$databaseUrl` is empty */
    public function __construct(
        private readonly DefinitionProvider $provider,
        private readonly RateLimiter $rateLimiter,
        private readonly ?string $databaseUrl = null,
        private readonly ?object $dbal = null,
    ) {}

    public function definition(): ServerDefinition
    {
        return $this->definition ??= $this->provider->define();
    }

    /** The PostgreSQL connection: ACCORD_DATABASE_URL, else the Doctrine DBAL connection's PDO. */
    public function pdo(): \PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if ($this->databaseUrl !== null && $this->databaseUrl !== '') {
            return $this->pdo = Database::connect($this->databaseUrl, persistent: true);
        }
        if ($this->dbal === null || !method_exists($this->dbal, 'getNativeConnection')) {
            throw new \RuntimeException('accord: set accord.database.url (ACCORD_DATABASE_URL) or install doctrine/doctrine-bundle with a pdo_pgsql connection');
        }
        $native = $this->dbal->getNativeConnection();
        if (!$native instanceof \PDO || $native->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \RuntimeException('accord: the Doctrine DBAL connection must use the pdo_pgsql driver (or set ACCORD_DATABASE_URL)');
        }

        // The server's PDO settings (errors as exceptions, native prepares, assoc fetches): the server
        // applies them only to connections it opens or receives as a factory.
        return $this->pdo = Database::options($native);
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    public function handler(): SyncHandler
    {
        $factory = new Psr17Factory();

        return AccordServer::handler($this->definition(), fn(): \PDO => $this->pdo(), $factory, $factory, $this->rateLimiter);
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
}
