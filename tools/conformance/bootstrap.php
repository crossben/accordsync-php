<?php

declare(strict_types=1);

/*
 * Shared by the conformance front controllers: autoloader, profile, database, rate-limit store.
 *
 *   ACCORD_DATABASE_URL  postgres://user:password@host:port/database (required)
 *   ACCORD_RATE_FILE     where the rate-limit buckets live (default: the system temp dir)
 */

use Accord\Server\Database;
use Accord\Server\RateLimit\FileRateLimiter;
use Accord\Server\ServerDefinition;

require __DIR__ . '/../../vendor/autoload.php';

/** @var ServerDefinition $definition */
$definition = require __DIR__ . '/profile.php';

$databaseUrl = getenv('ACCORD_DATABASE_URL');
if (!\is_string($databaseUrl) || $databaseUrl === '') {
    throw new RuntimeException('ACCORD_DATABASE_URL is required');
}
$connect = static fn(): PDO => Database::connect($databaseUrl, persistent: true);

$rateFile = getenv('ACCORD_RATE_FILE');
$rateLimiter = new FileRateLimiter(\is_string($rateFile) && $rateFile !== '' ? $rateFile : sys_get_temp_dir() . '/accord-conformance-rate.json');

return [$definition, $connect, $rateLimiter];
