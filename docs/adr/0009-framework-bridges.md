# ADR-P09: the Laravel and Symfony bridges only wire the server in

**Status:** accepted (P4)

## Decision

`accordsync/laravel` and `accordsync/symfony` contain no sync logic. Each provides the same five
things, built the same way:

| | Laravel | Symfony |
| --- | --- | --- |
| Definition | `config('accord.definition')`: an invokable class name (works with `config:cache`) or a closure; parameters injected by the container | the one service implementing `Accord\Symfony\DefinitionProvider` (autoconfigured with the tag `accord.definition`); zero or several is a container error |
| Routes | `/{prefix}/health`, `/{prefix}/v1/push`, `/{prefix}/v1/pull`, any method, outside the `web` group (no session, cookies, CSRF); prefix default `accord` | the same paths from the route loader type `accord`, `_stateless`; `accord.prefix` default `accord` |
| HTTP | the framework request becomes a `nyholm/psr7` `ServerRequest` handed to `SyncHandler`; the response is copied back | same |
| Database | `ACCORD_DATABASE_URL` (own persistent PDO) if set, else the PDO of a Laravel `pgsql` connection | `accord.database.url` if set, else the Doctrine DBAL connection's PDO (`pdo_pgsql`) |
| Rate limits | `CacheRateLimiter` on a Laravel cache store, under the store's atomic lock | `CacheRateLimiter` on a PSR-6 pool, under a Symfony lock (`LockFactory`) |
| CLI | `artisan accord:migrate`, `accord:compact`; compaction registered with the scheduler from `compaction.intervalMs` | `bin/console accord:migrate`, `accord:compact`; schedule with cron (documented) |
| JWKS cache | the injected cache `Repository` (PSR-16) passed to `Auth::jwks(cache: ...)` | `Psr\SimpleCache\CacheInterface $accordCache` autowired (a `Psr16Cache` over `accord.jwks_cache_pool`) |

Details that keep the two bridges identical to the plain PSR-15 front controller:

- The path is passed to the handler relative to the prefix, and the **raw query string** is parsed
  with `parse_str` (Laravel's middleware turns `?cursor=` into `null`).
- Headers PHP derives from the request (`php-auth-*`, decoded from a Basic `Authorization` header) and
  values PSR-7 refuses (control characters) are dropped before the handler sees them: otherwise a
  Basic header with binary credentials turns a 401 into a 500.
- The framework's PDO gets the server's settings (`Database::options`: exceptions, native prepares,
  assoc fetches); the server applies them only to connections it opens or gets through a factory.
  Laravel and DBAL set fetch modes per statement, so their own queries are unaffected.
- Rate-limit `clear()` bumps a generation number in the cache instead of deleting keys, so it never
  flushes the rest of the app's cache. The token-bucket arithmetic is `TokenBucket`, shared with the
  file and in-memory stores (ADR-P07).

## Why

- Plan §2: a behaviour in one bridge and not the other is a bug, so both bridges have the same shape
  and the example apps share the profile and control code (`examples/shared`).
- Symfony's configuration tree cannot hold closures, and a tagged service is what plan §3 asks for; a
  Laravel config file can, but `config:cache` cannot serialise them, hence the class-name form.
- `nyholm/psr7` is already the server's test PSR-7 implementation; a direct adapter is twenty lines,
  smaller than `symfony/psr-http-message-bridge` plus a factory.
- The Laravel `RateLimiter` facade and Symfony's `rate-limiter` component count with different
  arithmetic (fixed window; their own token bucket). Using only the framework's cache and locks keeps
  the TypeScript server's numbers.

## Consequences

- Rate limits are only as shared as the store: the file cache and flock are per host; several hosts
  need Redis (or another shared store with locks).
- PHP opens a database connection per request unless it is persistent; PostgreSQL's SCRAM handshake
  then costs about 10 ms a request. The example apps use persistent connections and the READMEs say so.
- `/metrics` is still not ported (ADR-P06).
