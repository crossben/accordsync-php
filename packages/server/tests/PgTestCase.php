<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Server\Database;
use PHPUnit\Framework\TestCase;

/**
 * Tests that need PostgreSQL: set ACCORD_TEST_DATABASE_URL to a server where the user may create
 * databases (e.g. postgres://accord:accord@127.0.0.1:55441/accord). Each test gets a new database.
 */
abstract class PgTestCase extends TestCase
{
    /** @var list<string> */
    private array $created = [];

    protected static function adminUrl(): string
    {
        $url = getenv('ACCORD_TEST_DATABASE_URL');
        if (!\is_string($url) || $url === '') {
            self::markTestSkipped('ACCORD_TEST_DATABASE_URL is not set');
        }

        return $url;
    }

    /** A new, empty database: its URL. */
    protected function freshDatabase(): string
    {
        $admin = self::adminUrl();
        $name = 'accord_test_' . bin2hex(random_bytes(6));
        Database::connect($admin)->exec("create database $name");
        $this->created[] = $name;

        return (string) preg_replace('#/[^/?]*(\?|$)#', "/$name$1", $admin, 1);
    }

    protected function tearDown(): void
    {
        if ($this->created !== []) {
            $pdo = Database::connect(self::adminUrl());
            foreach ($this->created as $name) {
                $pdo->exec("drop database if exists $name with (force)");
            }
            $this->created = [];
        }
    }

    /**
     * Everything about the schema that matters: tables, columns, constraints, indexes, functions,
     * triggers. Equal signatures mean the two databases were migrated to the same schema.
     *
     * @return array<string, list<string>>
     */
    protected static function schemaSignature(\PDO $pdo): array
    {
        $col = static fn(string $sql): array => array_map(strval(...), $pdo->query($sql)?->fetchAll(\PDO::FETCH_COLUMN) ?: []);

        return [
            'columns' => $col("select table_name || '.' || column_name || ' ' || data_type || ' ' || is_nullable || ' ' || coalesce(column_default, '-')
                from information_schema.columns where table_schema = 'public' order by 1"),
            'constraints' => $col("select conrelid::regclass || ' ' || conname || ' ' || pg_get_constraintdef(oid)
                from pg_constraint where connamespace = 'public'::regnamespace order by 1"),
            'indexes' => $col("select indexdef from pg_indexes where schemaname = 'public' order by 1"),
            'functions' => $col("select pg_get_functiondef(p.oid) from pg_proc p where pronamespace = 'public'::regnamespace order by proname"),
            'triggers' => $col("select tgname || ' ' || pg_get_triggerdef(oid) from pg_trigger where not tgisinternal order by 1"),
        ];
    }

    /** @return list<string> */
    protected static function ledger(\PDO $pdo): array
    {
        return array_map(strval(...), $pdo->query('select name from kysely_migration order by name')?->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }
}
