# ADR-P10: the example apps run the conformance suite under PHP's built-in server

**Status:** accepted (P4)

## Decision

- `examples/laravel-app` (Laravel 13) and `examples/symfony-app` (Symfony 7.4, DBAL only) are trimmed
  `laravel/laravel` and `symfony/skeleton` projects that require the local packages through path
  repositories. Their definition is the conformance profile read from
  `contract/conformance/profile.json` (`examples/shared/ConformanceProfile.php`, shared by both).
- Each app is served by `serve.sh`: `accord:migrate`, then `php -S` on `public/index.php` with
  `PHP_CLI_SERVER_WORKERS=16` for the sync API, and a second `php -S` process with
  `ACCORD_CONTROL_ENABLED` set for the control API (token, reset, compact, age-device). The control
  routes exist only in that process, never on the sync port. Laravel: 8845/8846, Symfony: 8847/8848.
- Laravel uses its `pgsql` connection and the `file` cache store; Symfony uses Doctrine DBAL
  (`pdo_pgsql`), `cache.app` (filesystem) and the framework lock (`LOCK_DSN=flock`). Both
  connections are persistent.

## Why

- `php artisan serve` and `symfony serve` are single-worker; the concurrency tests need parallel
  requests. `php -S` with workers is in every PHP 8.3 build on Linux; FrankenPHP or RoadRunner would
  add a binary to CI for no extra coverage.
- 16 workers rather than the plain harness's 8: a framework request costs a few milliseconds more,
  and the 429 test needs 150 simultaneous requests checked faster than the bucket refills (100/s).
  On this machine, with 8 workers Laravel saw only 6 or 7 rejections out of 150; with 16, 11 to
  20; Symfony about 30. Pinned to 4 CPUs (a GitHub runner), Laravel sees 14 to 20 and passed 7 of 8
  suite runs, Symfony about 26: the Laravel 429 test has the smallest margin. Each Laravel request
  spends about 3 ms in the framework before the handler; past 16 workers nothing improves.
- Laravel's `pgsql` connection omits `charset` and `search_path`: Laravel sends `set names` and
  `set search_path` on every request otherwise, even on a persistent connection (a few round trips
  that cut the 429 margin). `serve.sh` also caches config, routes and events, and runs with
  `opcache.validate_timestamps=0` (restart it after changing code).
- The control API is app code, not bridge code: it must never ship in a package.

## Consequences

`php -S` is a test server. Production runs PHP-FPM (or FrankenPHP/Octane) behind a web server; the
worker count bounds concurrent pushes there, as ADR-P06 says.
