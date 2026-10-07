# accordsync/symfony

The [Accord](https://accord.benhattab.pro) sync server inside a Symfony app: a bundle, the `accord:`
configuration, a route loader, `bin/console accord:migrate` / `accord:compact`, and the Doctrine DBAL
connection. All sync behaviour comes from [`accordsync/server`](https://packagist.org/packages/accordsync/server); this bundle only wires it
in. Symfony 7.2+ (tested with 7.4), PHP 8.3+, PostgreSQL.

## Install

```sh
composer require accordsync/symfony
```

Flex registers `Accord\Symfony\AccordBundle` (otherwise add it to `config/bundles.php`). Import the
routes:

```yaml
# config/routes/accord.yaml
accord:
    resource: .
    type: accord
```

## Declare the server

Implement `Accord\Symfony\DefinitionProvider` in exactly one service (it is autoconfigured with the
`accord.definition` tag). Ask for `Psr\SimpleCache\CacheInterface $accordCache` to cache the JWKS in
the app's cache, shared by every worker:

```php
namespace App\Accord;

use Accord\Core\Schema;
use Accord\Server\{Access, AccordServer, Auth, ScopedRecord, ServerDefinition};
use Accord\Symfony\DefinitionProvider;
use Psr\SimpleCache\CacheInterface;

final class Definition implements DefinitionProvider
{
    public function __construct(private readonly CacheInterface $accordCache) {}

    public function define(): ServerDefinition
    {
        return AccordServer::define(
            schema: Schema::define(['dossier' => ['agent' => Schema::lww(), 'visits' => Schema::counter()]]),
            scopes: ['dossier' => fn (ScopedRecord $r) => ScopedRecord::key('agent', $r->fields['agent'] ?? null)],
            access: fn (array $claims) => new Access(read: ["agent:{$claims['sub']}"], write: ["agent:{$claims['sub']}"]),
            auth: Auth::jwks('https://auth.example.com/.well-known/jwks.json', issuer: 'https://auth.example.com/', audience: 'accord', cache: $this->accordCache),
        );
    }
}
```

## Configuration

```yaml
# config/packages/accord.yaml (every key optional; defaults shown)
accord:
    prefix: accord              # /accord/health, /accord/v1/push, /accord/v1/pull; '' serves at the root
    database:
        url: ~                  # postgres://user:password@host:5432/db: Accord opens its own persistent connection
        connection: default     # otherwise this Doctrine DBAL connection's PDO (driver pdo_pgsql)
    rate_limit:
        cache_pool: cache.app   # PSR-6 pool for the rate-limit buckets
        lock_factory: ~         # a LockFactory service (e.g. lock.factory); ~: flock on this host
    jwks_cache_pool: cache.app  # behind the autowired CacheInterface $accordCache
```

Clients use `https://your-app/{prefix}` as their server URL.

## Run

```sh
bin/console accord:migrate     # creates or upgrades the Accord tables (same ledger as the TypeScript server)
```

Serve the app as usual (PHP-FPM, FrankenPHP...). Each PHP worker handles one request at a time, so
the worker pool size bounds concurrent pushes.

**Use a persistent database connection** (`doctrine.dbal.persistent: true`, or `accord.database.url`,
which is always persistent). Otherwise every sync request opens a PostgreSQL connection, about 10 ms
with SCRAM authentication.

## Compaction

`bin/console accord:compact` runs compaction once. Run it from cron at the definition's
`compaction.intervalMs` (default hourly):

```cron
0 * * * * cd /path/to/app && bin/console accord:compact
```

Compaction takes PostgreSQL's exclusive advisory lock, so overlapping runs or several servers do not
conflict. The bundle does not register a Symfony Scheduler task.

## Security notes

- The routes are `_stateless` and need no session, cookie or CSRF token. Requests authenticate with
  `Authorization: Bearer <jwt>` checked by the definition's `auth` (JWKS with issuer and audience in
  production; `Auth::hs256()` is for development and tests). Symfony Security is not used (ADR-P03);
  don't put the Accord paths behind a firewall that expects a session.
- CORS for the sync API is the definition's `cors` list, answered by the handler: don't add
  NelmioCorsBundle rules for these paths.
- Rate limits are as shared as the pool and the lock: the filesystem pool and flock work on one host;
  on several, use a Redis pool and a shared lock store (`lock_factory: lock.factory` with a Redis or
  PostgreSQL `LOCK_DSN`).

The tested setup is [`examples/symfony-app`](https://github.com/crossben/accordsync-php/tree/main/examples/symfony-app), which passes the Accord
server conformance suite (68 tests) in CI; see `docs/adr/0010-example-apps-and-serving.md`.
