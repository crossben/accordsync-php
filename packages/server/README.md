# accordsync/server

**The [Accord](https://accord.benhattab.pro) sync server for PHP, on PostgreSQL.**

A framework-agnostic PSR-15 request handler for `GET /health`, `POST /v1/push` and `GET /v1/pull`:
push and pull, scopes, JWT auth, limits, rate limits, CORS and compaction. It speaks the same
protocol, merges by the same rules and uses the same PostgreSQL schema as
[`@accordsync/server`](https://www.npmjs.com/package/@accordsync/server), so the TypeScript, React
Native, Flutter and Python clients sync with it unchanged.

In a Laravel or Symfony app, use [`accordsync/laravel`](https://packagist.org/packages/accordsync/laravel)
or [`accordsync/symfony`](https://packagist.org/packages/accordsync/symfony): they wire this package
into the framework (routes, config, database connection, cache, console commands). This page is for
plain PHP, or for another framework.

## Install

```sh
composer require accordsync/server
```

PHP 8.3+ with `pdo_pgsql`, and PostgreSQL (the conformance suite and the example apps use
PostgreSQL 16). The handler needs a PSR-7/PSR-17 implementation; the example below uses
`nyholm/psr7` and `nyholm/psr7-server`.

## Define the server

`AccordServer::define()` takes the same parts as `defineServer` in TypeScript: the schema, a scope
function per record type, the access a user gets from their JWT claims, and how tokens are checked.
It checks at once that every record type has a scope function. Keep the definition in a file that
returns it, so the front controller and `vendor/bin/accord compact` share it:

```php
<?php
// accord.php
use Accord\Core\Schema;
use Accord\Server\{Access, AccordServer, Auth, ScopedRecord};

return AccordServer::define(
    schema: Schema::define([
        'dossier' => [
            'agent' => Schema::lww(),
            'visits' => Schema::counter(),
            'docs' => Schema::set(),
            'status' => Schema::conflict(),
        ],
    ]),
    // The scope keys of a record, from its current fields.
    scopes: ['dossier' => fn (ScopedRecord $r) => ScopedRecord::key('agent', $r->fields['agent'] ?? null)],
    // The scope keys a user may read and write, from their verified JWT claims.
    access: fn (array $claims) => new Access(read: ["agent:{$claims['sub']}"], write: ["agent:{$claims['sub']}"]),
    auth: Auth::jwks('https://auth.example.com/.well-known/jwks.json', issuer: 'https://auth.example.com/', audience: 'accord'),
);
```

Optional arguments: `cors` (browser origins allowed to call the API), `rateLimit` (a `RateLimits`;
default 600 requests a minute per device and 1 800 per user; `false` turns it off), `compaction` (a
`Compaction`: device TTL, interval, minimum ops) and `limits` (a `Limits`: body size, ops per push,
pull page size, scope delta size, clock skew). `Auth::hs256($secret)` is for development and tests.

## Serve it

`AccordServer::handler()` returns a `Psr\Http\Server\RequestHandlerInterface`. It routes on the
request path, so mount it where the path it sees is `/health`, `/v1/push` or `/v1/pull`.

```php
<?php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';

use Accord\Server\{AccordServer, Database};
use Accord\Server\RateLimit\FileRateLimiter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$factory = new Psr17Factory();
$handler = AccordServer::handler(
    require __DIR__ . '/../accord.php',
    // Opened on first use; persistent, so each request does not pay for a new connection.
    fn () => Database::connect((string) getenv('ACCORD_DATABASE_URL'), persistent: true),
    $factory,
    $factory,
    // Rate-limit buckets shared by every PHP worker on this host.
    rateLimiter: new FileRateLimiter(sys_get_temp_dir() . '/accord-rate-limits.json'),
);

$response = $handler->handle((new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals());

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", false);
    }
}
echo $response->getBody();
```

`ACCORD_DATABASE_URL` is a URL like `postgres://user:password@host:5432/db`. Each PHP worker handles
one request at a time, so the worker pool size bounds concurrent pushes.

## Migrations and compaction

```sh
ACCORD_DATABASE_URL=postgres://… vendor/bin/accord migrate               # create or upgrade the tables
ACCORD_DATABASE_URL=postgres://… vendor/bin/accord compact accord.php    # compact once
```

Run `accord compact` from cron at the definition's `compaction.intervalMs` (default hourly). It takes
PostgreSQL's exclusive advisory lock, so overlapping runs, or several servers, do not conflict. In
code: `AccordServer::migrate($pdo)` and `AccordServer::compact($definition, $pdo)`.

## One database, any Accord server

The migrations are the TypeScript server's, recorded in the same ledger table (`kysely_migration`):
a database migrated by `@accordsync/server` is up to date here, and the other way round. One database
can be served by TypeScript and PHP servers at the same time, with clients sent to either. The
repository's `interop/` harness does exactly that: TypeScript devices send every request to a
randomly chosen server, through a network that loses requests and responses, and must end with
identical data. The repository's Laravel and Symfony example apps also pass Accord's black-box HTTP
conformance suite, the same one the TypeScript server passes.

## Security notes

- Requests authenticate with `Authorization: Bearer <jwt>`. In production use `Auth::jwks()` with an
  issuer and an audience; pass a PSR-16 `cache` so PHP workers share the downloaded keys.
- The default rate limiter keeps buckets in memory, which under PHP-FPM lasts one request: use
  `FileRateLimiter` on one host, and a shared store on several (the Laravel and Symfony packages use
  the framework's cache).
- Request bodies above `limits.maxBodyBytes` (default 5 MiB) get 413; PHP's own `post_max_size`
  still applies before it.
- CORS for the sync API is the definition's `cors` list, answered by the handler.
- What a user may read and write is decided only by your `access` function and scope functions.
  See [docs/security.md](https://github.com/crossben/accordsync/blob/main/docs/security.md).

Docs: [accord.benhattab.pro/docs/php](https://accord.benhattab.pro/docs/php/) ·
Source: [crossben/accordsync-php](https://github.com/crossben/accordsync-php) · Licence: Apache-2.0
