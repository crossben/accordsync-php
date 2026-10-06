<?php

declare(strict_types=1);

namespace Accord\Server;

use Accord\Core\Protocol;
use Accord\Core\Schema;
use Accord\Server\Http\SyncHandler;
use Accord\Server\RateLimit\InMemoryRateLimiter;
use Accord\Server\RateLimit\RateLimiter;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** The Accord sync server: declare it with {@see define()}, serve it with {@see handler()}. */
final class AccordServer
{
    public const int PROTOCOL_VERSION = Protocol::VERSION;

    /**
     * Declares an Accord server, like `defineServer` in TypeScript. Checked here: every record type
     * needs a scope function, and every scope function a record type.
     *
     * @param array<string, callable(ScopedRecord): list<string>> $scopes record type → scope keys of a record, from its current fields
     * @param callable(array<string, mixed>): Access $access the scope keys a user may read and write, from their verified JWT claims
     * @param list<string> $cors browser origins allowed to call the sync API
     * @param RateLimits|false|null $rateLimit null: the defaults (600/min per device, 1 800/min per user); false: none
     */
    public static function define(
        Schema $schema,
        array $scopes,
        callable $access,
        Auth $auth,
        array $cors = [],
        RateLimits|false|null $rateLimit = null,
        ?Compaction $compaction = null,
        ?Limits $limits = null,
    ): ServerDefinition {
        $fns = [];
        foreach ($schema->types() as $type) {
            if (!isset($scopes[$type])) {
                throw new \InvalidArgumentException("AccordServer::define: no scope function for record type \"$type\"");
            }
            $fns[$type] = \Closure::fromCallable($scopes[$type]);
        }
        foreach (array_keys($scopes) as $type) {
            if (!isset($fns[(string) $type])) {
                throw new \InvalidArgumentException("AccordServer::define: scope function for unknown record type \"$type\"");
            }
        }
        foreach ($cors as $origin) {
            if ($origin === '') {
                throw new \InvalidArgumentException('AccordServer::define: cors must list origins as strings');
            }
        }

        /** @var array<string, \Closure(ScopedRecord): array<mixed>> $fns */
        return new ServerDefinition(
            $schema,
            $fns,
            \Closure::fromCallable($access),
            $auth,
            $cors,
            $rateLimit === false ? null : ($rateLimit ?? new RateLimits()),
            $compaction ?? new Compaction(),
            $limits ?? new Limits(),
        );
    }

    /**
     * The PSR-15 handler for `GET /health`, `POST /v1/push`, `GET /v1/pull`.
     *
     * @param \PDO|\Closure(): \PDO $db a PostgreSQL connection, or a factory called on first use
     * @param ?RateLimiter $rateLimiter shared state for rate limits; the in-memory default only limits
     *        within one PHP process (use the framework cache in production, ADR-P07)
     * @param ?\Closure(): int $now physical time in ms (tests)
     */
    public static function handler(
        ServerDefinition $def,
        \PDO|\Closure $db,
        ResponseFactoryInterface $responses,
        StreamFactoryInterface $streams,
        ?RateLimiter $rateLimiter = null,
        ?\Closure $now = null,
    ): SyncHandler {
        return new SyncHandler($def, new Database($db), $responses, $streams, $rateLimiter ?? new InMemoryRateLimiter(), $now);
    }

    /** Applies the pending migrations (the TypeScript server's, same ledger). */
    public static function migrate(\PDO $pdo): void
    {
        (new Migrator($pdo))->migrateToLatest();
    }

    /** Runs compaction once (what `accord compact` does). */
    public static function compact(ServerDefinition $def, \PDO $pdo): CompactionResult
    {
        return (new Compactor($def, new Database($pdo)))->run();
    }
}
