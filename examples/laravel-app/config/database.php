<?php

declare(strict_types=1);

// Only what differs from Laravel's defaults: a persistent pgsql connection (PHP opens a new
// connection per request otherwise, and PostgreSQL's SCRAM handshake costs about 10 ms), without
// `charset` and `search_path`: Laravel sends `set names` and `set search_path` on every request
// otherwise, even on a persistent connection. The database's defaults (UTF8, public) are the same.
return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'prefix' => '',
            'prefix_indexes' => true,
            'sslmode' => 'prefer',
            'options' => [PDO::ATTR_PERSISTENT => true],
        ],
    ],
];
