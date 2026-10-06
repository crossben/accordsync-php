<?php

declare(strict_types=1);

/*
 * Accord sync server (accordsync/laravel). Publish with:
 *
 *   php artisan vendor:publish --tag=accord-config
 */

return [
    /*
     * The server definition: what AccordServer::define() returns (schema, scopes, access, auth,
     * limits...). One of:
     *
     *  - the class name of an invokable class returning a ServerDefinition (works with config:cache);
     *    its __invoke() parameters are injected by the container, e.g. a cache Repository for
     *    Auth::jwks(..., cache: $cache);
     *  - a closure returning a ServerDefinition (parameters injected the same way). Closures cannot
     *    be serialised, so `php artisan config:cache` fails with one: use a class in production.
     */
    'definition' => env('ACCORD_DEFINITION'),

    /*
     * The sync API is served at /{prefix}/v1/push, /{prefix}/v1/pull and /{prefix}/health. Clients
     * use https://your-app/{prefix} as their server URL. An empty prefix serves it at the root.
     */
    'prefix' => env('ACCORD_PREFIX', 'accord'),

    /*
     * Route middleware for the sync routes. The routes are outside the "web" group on purpose (no
     * session, cookies or CSRF): requests authenticate with a JWT. Add only stateless middleware.
     */
    'middleware' => [],

    /*
     * PostgreSQL. When `url` is set (postgres://user:password@host:5432/database), Accord opens its
     * own persistent PDO connection to it. Otherwise it uses the PDO of the Laravel connection named
     * `connection` (null: the default connection), which must use the pgsql driver.
     */
    'database' => [
        'url' => env('ACCORD_DATABASE_URL'),
        'connection' => env('ACCORD_DB_CONNECTION'),
    ],

    /*
     * Rate-limit buckets are kept in this cache store (null: the default store). It must support
     * atomic locks and be shared by every worker and host: redis, memcached, database, or file on a
     * single host. The array store only limits within one request.
     */
    'rate_limit' => [
        'store' => env('ACCORD_RATE_LIMIT_STORE'),
    ],

    /*
     * Register `accord:compact` with Laravel's scheduler, every `compaction.intervalMs` of the
     * definition (rounded to whole minutes or hours). Needs `php artisan schedule:run` from cron.
     */
    'schedule' => env('ACCORD_SCHEDULE', true),
];
