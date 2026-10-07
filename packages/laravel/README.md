# accordsync/laravel

The [Accord](https://accord.benhattab.pro) sync server inside a Laravel app: a service provider,
`config/accord.php`, the sync routes, `php artisan accord:migrate` / `accord:compact`, and compaction
in the scheduler. All sync behaviour comes from [`accordsync/server`](https://packagist.org/packages/accordsync/server); this package only
wires it in. Laravel 11+ (tested with 13), PHP 8.3+, PostgreSQL.

## Install

```sh
composer require accordsync/laravel
php artisan vendor:publish --tag=accord-config
```

The provider is auto-discovered.

## Declare the server

`config/accord.php` points at your definition: what `AccordServer::define()` returns (schema, a scope
function per record type, access from JWT claims, auth, limits). Use an invokable class so that
`php artisan config:cache` keeps working; its `__invoke()` parameters are injected by the container.

```php
// config/accord.php
'definition' => App\Accord\Definition::class,
```

```php
namespace App\Accord;

use Accord\Core\Schema;
use Accord\Server\{Access, AccordServer, Auth, ScopedRecord, ServerDefinition};
use Illuminate\Contracts\Cache\Repository;

final class Definition
{
    public function __invoke(Repository $cache): ServerDefinition
    {
        return AccordServer::define(
            schema: Schema::define(['dossier' => ['agent' => Schema::lww(), 'visits' => Schema::counter()]]),
            scopes: ['dossier' => fn (ScopedRecord $r) => ScopedRecord::key('agent', $r->fields['agent'] ?? null)],
            access: fn (array $claims) => new Access(read: ["agent:{$claims['sub']}"], write: ["agent:{$claims['sub']}"]),
            // The JWKS is cached in Laravel's cache, shared by every worker.
            auth: Auth::jwks('https://auth.example.com/.well-known/jwks.json', issuer: 'https://auth.example.com/', audience: 'accord', cache: $cache),
        );
    }
}
```

A closure returning the definition also works, but closures cannot be cached by `config:cache`.

## Configuration

| Key | Env | Default | |
| --- | --- | --- | --- |
| `definition` | `ACCORD_DEFINITION` | none | class name or closure (above) |
| `prefix` | `ACCORD_PREFIX` | `accord` | routes are `/{prefix}/health`, `/{prefix}/v1/push`, `/{prefix}/v1/pull`; clients use `https://your-app/{prefix}` as their server URL. Empty: served at the root |
| `middleware` | | `[]` | extra route middleware (keep it stateless) |
| `database.url` | `ACCORD_DATABASE_URL` | none | `postgres://user:password@host:5432/db`: Accord opens its own persistent connection |
| `database.connection` | `ACCORD_DB_CONNECTION` | default connection | otherwise the PDO of this Laravel connection, which must use the `pgsql` driver |
| `rate_limit.store` | `ACCORD_RATE_LIMIT_STORE` | default store | cache store holding the rate-limit buckets; it must support atomic locks |
| `schedule` | `ACCORD_SCHEDULE` | `true` | register `accord:compact` with the scheduler |

## Run

```sh
php artisan accord:migrate     # creates or upgrades the Accord tables (same ledger as the TypeScript server)
```

Serve the app as usual (PHP-FPM, FrankenPHP...). Each PHP worker handles one request at a time, so
the worker pool size bounds concurrent pushes.

**Use a persistent database connection** (`'options' => [PDO::ATTR_PERSISTENT => true]` on the `pgsql`
connection, or `ACCORD_DATABASE_URL`, which is always persistent). Otherwise every sync request opens
a PostgreSQL connection, about 10 ms with SCRAM authentication. Laravel also runs `set names` and
`set search_path` when it opens a connection, persistent or not, if the connection config has
`charset` or `search_path`: leave them out when the database defaults are right.

## Compaction

`php artisan accord:compact` runs compaction once. When the definition's `compaction.intervalMs` is
above 0 (default one hour) and `schedule` is on, the provider registers `accord:compact` with
Laravel's scheduler (`withoutOverlapping()`; intervals are rounded to whole minutes under an hour,
whole hours above), so the usual `* * * * * php artisan schedule:run` cron entry runs it. Compaction
takes PostgreSQL's exclusive advisory lock, so two servers compacting at once do not conflict.

## Security notes

- The routes are outside the `web` middleware group: no session, cookies or CSRF token. Requests
  authenticate with `Authorization: Bearer <jwt>` checked by the definition's `auth` (JWKS with issuer
  and audience in production; `Auth::hs256()` is for development and tests). Laravel guards (Sanctum,
  sessions) are not used (ADR-P03).
- Don't add the Accord paths to `config/cors.php`: CORS for the sync API is the definition's `cors`
  list, answered by the handler.
- Rate limits are as shared as the cache store: `file` works on one host; use Redis (or another
  shared store with locks) on several. The `array` store does not limit anything across requests.
- Request bodies above the definition's `maxBodyBytes` get 413 from the handler; PHP's own
  `post_max_size` still applies before it.

The tested setup is [`examples/laravel-app`](https://github.com/crossben/accordsync-php/tree/main/examples/laravel-app), which passes the Accord
server conformance suite (68 tests) in CI; see `docs/adr/0010-example-apps-and-serving.md`.
