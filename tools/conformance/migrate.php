<?php

declare(strict_types=1);

/* Applies the Accord migrations to ACCORD_DATABASE_URL (same ledger as the TypeScript server). */

use Accord\Server\Database;
use Accord\Server\Migrator;

require __DIR__ . '/../../vendor/autoload.php';

$url = getenv('ACCORD_DATABASE_URL');
if (!\is_string($url) || $url === '') {
    fwrite(STDERR, "ACCORD_DATABASE_URL is required\n");
    exit(1);
}
$applied = (new Migrator(Database::connect($url)))->migrateToLatest();
echo $applied === [] ? "accord: database is up to date\n" : 'accord: applied ' . implode(', ', $applied) . "\n";
