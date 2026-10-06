# Accord for PHP

**Offline-first sync that stays correct when the network lies**, served from your Laravel or Symfony
backend.

Apps keep working with no connection. When it comes back, every device ends up with the same data:
changes merge by rules you declare per field, counters never lose an increment, and conflicting
decisions are kept for your app to settle instead of being guessed.

This is the Accord **server** in PHP. It speaks the same protocol, merges by the same rules and uses
the same PostgreSQL schema as [`@accordsync/server`](https://github.com/crossben/accordsync), so the
existing TypeScript, React Native and Flutter clients sync with it unchanged.

> **Status: in development.** Not published on Packagist yet. Website and docs:
> [accord.benhattab.pro](https://accord.benhattab.pro).

## Packages

| Package | What it does |
| --- | --- |
| `accordsync/core` | The merge core: hybrid logical clocks, operations, `lww`, `counter`, `set` and `conflict`. |
| `accordsync/server` | The sync server on PostgreSQL, framework-agnostic (PSR-15): push, pull, scopes, auth, compaction. |
| `accordsync/laravel` | Laravel integration: service provider, config, routes, artisan commands. |
| `accordsync/symfony` | Symfony integration: bundle, configuration, routes, console commands. |

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
