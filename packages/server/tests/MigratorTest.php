<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Server\Database;
use Accord\Server\Migrator;

/** ADR-P02: same schema and same ledger as the TypeScript server, in both directions. */
final class MigratorTest extends PgTestCase
{
    public function testMigratesAFreshDatabaseOnceAndRecordsTheLedger(): void
    {
        $pdo = Database::connect($this->freshDatabase());
        self::assertSame(Migrator::NAMES, (new Migrator($pdo))->migrateToLatest());
        self::assertSame([], (new Migrator($pdo))->migrateToLatest());
        self::assertSame(Migrator::NAMES, self::ledger($pdo));
        $timestamps = $pdo->query('select timestamp from kysely_migration')?->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        foreach ($timestamps as $t) {
            self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', (string) $t, 'ISO like Date.toISOString()');
        }
        self::assertSame([['id' => 'migration_lock', 'is_locked' => 0]], $pdo->query('select id, is_locked from kysely_migration_lock')?->fetchAll(\PDO::FETCH_ASSOC));
        // The feed is append-only and positions come from accord_pos().
        $pdo->exec("insert into feed (kind, record, scopes, scopes_before) values ('scope', 'dossier:1', '{a}', '{}')");
        self::assertGreaterThan(0, (int) $pdo->query('select pos from feed')?->fetchColumn());
        $this->expectExceptionMessage('the feed is append-only');
        $pdo->exec('delete from feed');
    }

    public function testUpgradesStepByStepKeepingDataLikeTheTypeScriptMigration(): void
    {
        $pdo = Database::connect($this->freshDatabase());
        self::assertSame(['0001_meta', '0002_sync', '0003_compaction', '0004_record_state'], (new Migrator($pdo))->migrateTo('0004_record_state'));
        $pdo->exec("insert into feed (kind, record, op_id, op, scopes) values ('op', 'dossier:1', 'dev:4', '{}', '{a}'), ('op', 'dossier:1', 'dev:9', '{}', '{a}')");
        $pdo->exec("insert into devices (device_id, sub) values ('dev', 'alice')");
        $pdo->exec("insert into compacted_ops (op_id) values ('dev:2')");
        self::assertSame(['0005_concurrent_pushes', '0006_compacted_op_hash'], (new Migrator($pdo))->migrateToLatest());
        self::assertSame(3, (int) $pdo->query('select accord_xid_offset()')?->fetchColumn());
        self::assertSame([1, 2], array_map(intval(...), $pdo->query('select pos from feed order by seq')?->fetchAll(\PDO::FETCH_COLUMN) ?: []));
        self::assertSame(['max_op_seq' => 9, 'needs_resync' => true, 'cursor' => 0], $pdo->query("select max_op_seq, needs_resync, cursor from devices")?->fetch(\PDO::FETCH_ASSOC));
        self::assertSame(['device' => 'dev', 'op_seq' => 2, 'op_hash' => null], $pdo->query('select device, op_seq, op_hash from compacted_ops')?->fetch(\PDO::FETCH_ASSOC));
    }

    public function testRefusesALedgerWithAnUnknownMigration(): void
    {
        $pdo = Database::connect($this->freshDatabase());
        (new Migrator($pdo))->migrateToLatest();
        $pdo->exec("insert into kysely_migration values ('0099_future', '2030-01-01T00:00:00.000Z')");
        $this->expectExceptionMessage('previously executed migration 0099_future is missing');
        (new Migrator($pdo))->migrateToLatest();
    }

    public function testSameSchemaAsTheTypeScriptServerInBothDirections(): void
    {
        $tsDb = $this->freshDatabase();
        $phpDb = $this->freshDatabase();
        $mixedDb = $this->freshDatabase();
        self::tsMigrate($tsDb);
        (new Migrator(Database::connect($phpDb)))->migrateToLatest();

        // TypeScript then PHP: nothing left to do, same ledger.
        $ts = Database::connect($tsDb);
        self::assertSame([], (new Migrator($ts))->migrateToLatest());
        self::assertSame(Migrator::NAMES, self::ledger($ts));
        // PHP then TypeScript: Kysely accepts the ledger and finds nothing to do.
        $php = Database::connect($phpDb);
        $before = self::ledgerRows($php);
        self::tsMigrate($phpDb);
        self::assertSame($before, self::ledgerRows($php));
        // Half and half: TypeScript up to 0003, PHP the rest, TypeScript again.
        self::tsMigrate($mixedDb, '0003_compaction');
        $mixed = Database::connect($mixedDb);
        self::assertSame(['0004_record_state', '0005_concurrent_pushes', '0006_compacted_op_hash'], (new Migrator($mixed))->migrateToLatest());
        self::tsMigrate($mixedDb);

        self::assertSame(self::schemaSignature($ts), self::schemaSignature($php));
        self::assertSame(self::schemaSignature($ts), self::schemaSignature($mixed));
    }

    /** @return list<array<string, mixed>> */
    private static function ledgerRows(\PDO $pdo): array
    {
        /** @var list<array<string, mixed>> */
        return $pdo->query('select * from kysely_migration order by name')?->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** Runs app/packages/server's migrator (needs ACCORD_APP_DIR and an installed workspace). */
    private static function tsMigrate(string $url, string $target = ''): void
    {
        $app = getenv('ACCORD_APP_DIR');
        if (!\is_string($app) || !is_dir("$app/packages/server/node_modules")) {
            self::markTestSkipped('ACCORD_APP_DIR (an installed Accord workspace) is not set');
        }
        $script = \dirname(__DIR__, 3) . '/tools/conformance/ts-migrate.mts';
        $cmd = 'cd ' . escapeshellarg("$app/packages/server") . ' && node --import tsx --conditions=@accordsync/source '
            . escapeshellarg($script) . ' ' . escapeshellarg($url) . ' ' . escapeshellarg($target) . ' 2>&1';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}
