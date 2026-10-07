# Accord for PHP

**Offline-first sync that stays correct when the network lies**, served from your Laravel or Symfony
backend.

Apps keep working with no connection. When it comes back, every device ends up with the same data:
changes merge by rules you declare per field, counters never lose an increment, and conflicting
decisions are kept for your app to settle instead of being guessed.

This is the Accord **server** in PHP. It speaks the same protocol, merges by the same rules and uses
the same PostgreSQL schema as [`@accordsync/server`](https://github.com/crossben/accordsync), so the
TypeScript, React Native, Flutter and Python clients sync with it unchanged.

> **Status: v0.3.0.** Pre-1.0: the API may still change between minor versions. Website and docs:
> [accord.benhattab.pro](https://accord.benhattab.pro/docs/php/).

```sh
composer require accordsync/laravel    # Laravel
composer require accordsync/symfony    # Symfony
composer require accordsync/server     # plain PHP or another framework (PSR-15)
```

## Packages

| Package | What it does |
| --- | --- |
| `accordsync/core` | The merge core: hybrid logical clocks, operations, `lww`, `counter`, `set` and `conflict`. |
| `accordsync/server` | The sync server on PostgreSQL, framework-agnostic (PSR-15): push, pull, scopes, auth, compaction. |
| `accordsync/laravel` | Laravel integration: service provider, config, routes, artisan commands. |
| `accordsync/symfony` | Symfony integration: bundle, configuration, routes, console commands. |

## How compatibility is proven

- `contract/` holds the golden vectors and protocol schemas from the Accord repository: the core
  passes every vector in every delivery order, and reproduces the TypeScript core's random scenarios
  byte for byte.
- CI runs Accord's black-box HTTP conformance suite (`conformance/` in the Accord repository)
  against the PHP server and against the Laravel and Symfony example apps.
- `interop/` runs a mixed-server fleet: the TypeScript and PHP servers on one PostgreSQL database at
  the same time, with TypeScript devices sending every request to either, over a network that loses
  requests and responses. Every device must end with identical data.

## Develop

Requires PHP 8.3+ and Composer.

```sh
composer install
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

`contract/` holds the golden vectors and protocol schemas from the Accord repository; this
implementation must pass them. Refresh it with `composer sync-contract` (reads `../app`, or
`ACCORD_APP_DIR`).

## Licence

[Apache-2.0](LICENSE).
