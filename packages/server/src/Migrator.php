<?php

declare(strict_types=1);

namespace Accord\Server;

/**
 * The TypeScript server's migrations, same SQL, recorded in the same ledger (Kysely's
 * `kysely_migration`) under the same names and lock, so a database migrated by either server is
 * recognised by the other (ADR-P02, ADR-P06). Migrations are append-only: a new one is written in
 * the Accord repository first, then ported here under the same name.
 */
final class Migrator
{
    /** Kysely's PostgreSQL migration lock (`PostgresAdapter.acquireMigrationLock`). */
    public const string KYSELY_LOCK_ID = '3853314791062309107';

    public const array NAMES = [
        '0001_meta',
        '0002_sync',
        '0003_compaction',
        '0004_record_state',
        '0005_concurrent_pushes',
        '0006_compacted_op_hash',
    ];

    public function __construct(private readonly \PDO $pdo)
    {
        Database::options($pdo);
    }

    /** @return list<string> the migrations applied now */
    public function migrateToLatest(): array
    {
        return $this->migrateTo(null);
    }

    /**
     * Applies pending migrations up to `$target` (a name), or all of them.
     *
     * @return list<string>
     */
    public function migrateTo(?string $target): array
    {
        if ($target !== null && !\in_array($target, self::NAMES, true)) {
            throw new \InvalidArgumentException("unknown migration \"$target\"");
        }
        $pdo = $this->pdo;
        // As Kysely's Migrator: ledger tables and lock row first, outside the transaction.
        $pdo->exec('create table if not exists "kysely_migration" ("name" varchar(255) not null primary key, "timestamp" varchar(255) not null)');
        $pdo->exec('create table if not exists "kysely_migration_lock" ("id" varchar(255) not null primary key, "is_locked" integer default 0 not null)');
        $pdo->exec('insert into "kysely_migration_lock" ("id", "is_locked") values (\'migration_lock\', 0) on conflict ("id") do nothing');

        $pdo->exec("with set_timeout as (select set_config('lock_timeout', '3600000', true) as config_val)
            select pg_advisory_lock(" . self::KYSELY_LOCK_ID . ') from set_timeout');
        try {
            $pdo->beginTransaction();
            try {
                // The lock row too (ADR-P02), for older Kysely versions that locked it instead.
                $pdo->exec('select "id" from "kysely_migration_lock" where "id" = \'migration_lock\' for update');
                $done = $this->executed();
                $applied = [];
                foreach (self::NAMES as $name) {
                    if (\in_array($name, $done, true)) {
                        continue;
                    }
                    if ($target !== null && strcmp($name, $target) > 0) {
                        break;
                    }
                    $this->up($name);
                    $st = $pdo->prepare('insert into "kysely_migration" ("name", "timestamp") values (?, ?)');
                    $st->execute([$name, self::isoNow()]);
                    $applied[] = $name;
                }
                $pdo->commit();

                return $applied;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            $pdo->exec('select pg_advisory_unlock(' . self::KYSELY_LOCK_ID . ')');
        }
    }

    /**
     * Applied migrations, checked like Kysely does: none unknown, and they are the first ones in order.
     *
     * @return list<string>
     */
    private function executed(): array
    {
        $names = array_map(Database::str(...), $this->column('select "name" from "kysely_migration"'));
        sort($names, SORT_STRING);
        foreach ($names as $i => $name) {
            if (!\in_array($name, self::NAMES, true)) {
                throw new \RuntimeException("corrupted migrations: previously executed migration $name is missing");
            }
            if (self::NAMES[$i] !== $name) {
                throw new \RuntimeException("corrupted migrations: expected previously executed migration $name to be at index $i");
            }
        }

        return $names;
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $st = $this->pdo->query($sql);
        if ($st === false) {
            throw new \RuntimeException("query failed: $sql");
        }

        return array_values($st->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function isoNow(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function up(string $name): void
    {
        $exec = $this->pdo->exec(...);
        switch ($name) {
            case '0001_meta':
                $exec('create table "accord_meta" ("key" text primary key, "value" text not null)');
                $exec("insert into accord_meta (key, value) values ('schema_created_at', now()::text)");
                break;
            case '0002_sync':
                $exec(<<<'SQL'
                    create table "feed" ("seq" bigserial primary key, "kind" text not null check (kind in ('op', 'scope')), "record" text not null, "op_id" text unique, "op" jsonb, "scopes" text[] not null, "scopes_before" text[], constraint "feed_shape" check ((kind = 'op' and op_id is not null and op is not null and scopes_before is null)
                           or (kind = 'scope' and op_id is null and op is null and scopes_before is not null)))
                    SQL);
                $exec('create index "feed_record_seq" on "feed" ("record", "seq")');
                $exec('create index "feed_scopes" on "feed" using gin ("scopes")');
                $exec(<<<'SQL'

                        create function accord_feed_guard() returns trigger language plpgsql as $$
                        begin
                          if tg_op = 'DELETE' and current_setting('accord.compaction', true) = 'on' then
                            return old;
                          end if;
                          raise exception 'accord: the feed is append-only (% refused)', tg_op;
                        end $$
                    SQL);
                $exec(<<<'SQL'

                        create trigger accord_feed_append_only before update or delete on feed
                        for each row execute function accord_feed_guard()
                    SQL);
                $exec('create table "records" ("record" text primary key, "scopes" text[] not null)');
                $exec('create table "devices" ("device_id" text primary key, "sub" text not null, "read_keys" text[], "first_seen" timestamptz default now() not null, "last_seen" timestamptz default now() not null)');
                break;
            case '0003_compaction':
                $exec('alter table feed drop constraint feed_kind_check');
                $exec('alter table feed drop constraint feed_shape');
                $exec("alter table feed add constraint feed_kind_check check (kind in ('op', 'scope', 'snapshot'))");
                $exec(<<<'SQL'
                    alter table feed add constraint feed_shape check (
                           (kind = 'op' and op_id is not null and op is not null and scopes_before is null)
                        or (kind = 'scope' and op_id is null and op is null and scopes_before is not null)
                        or (kind = 'snapshot' and op_id is null and op is not null and scopes_before is null))
                    SQL);
                $exec('create table "compacted_ops" ("op_id" text primary key)');
                $exec('alter table "devices" add column "cursor" bigint default 0 not null, add column "needs_resync" boolean default false not null');
                break;
            case '0004_record_state':
                $exec('alter table "records" add column "state" jsonb');
                break;
            case '0005_concurrent_pushes':
                $offset = Database::int($this->column('select coalesce(max(seq), 0) + 1 as next from feed')[0] ?? null);
                // A constant, so the column default and every query agree on it forever.
                $exec("create function accord_xid_offset() returns bigint language sql immutable as \$\$ select {$offset}::bigint \$\$");
                $exec(<<<'SQL'
                    create function accord_pos() returns bigint language sql volatile as $$
                          select pg_current_xact_id()::text::bigint + accord_xid_offset() $$
                    SQL);
                $exec(<<<'SQL'
                    create function accord_horizon() returns bigint language sql volatile as $$
                          select pg_snapshot_xmin(pg_current_snapshot())::text::bigint + accord_xid_offset() $$
                    SQL);
                $exec('alter table feed add column pos bigint');
                $exec("select set_config('accord.compaction', 'on', true)");
                $exec('alter table feed disable trigger accord_feed_append_only');
                $exec('update feed set pos = seq');
                $exec('alter table feed enable trigger accord_feed_append_only');
                $exec('alter table feed alter column pos set not null');
                $exec('alter table feed alter column pos set default accord_pos()');
                $exec('create index "feed_pos_seq" on "feed" ("pos", "seq")');
                // Cursors were feed `seq` values; they now count in `pos`. Every device starts again from zero.
                $exec('update devices set cursor = 0, needs_resync = true');
                $exec('alter table devices add column push_floor bigint not null default 0');
                $exec('alter table devices add column max_op_seq bigint not null default 0');
                $exec(<<<'SQL'
                    update devices d set max_op_seq = coalesce((select max(split_part(op_id, ':', 2)::bigint)
                          from feed f where f.kind = 'op' and split_part(f.op_id, ':', 1) = d.device_id), 0)
                    SQL);
                $exec('alter table compacted_ops add column device text');
                $exec('alter table compacted_ops add column op_seq bigint');
                $exec("update compacted_ops set device = split_part(op_id, ':', 1), op_seq = split_part(op_id, ':', 2)::bigint");
                $exec('alter table compacted_ops alter column device set not null');
                $exec('alter table compacted_ops alter column op_seq set not null');
                $exec('create index "compacted_ops_device" on "compacted_ops" ("device", "op_seq")');
                break;
            case '0006_compacted_op_hash':
                $exec('alter table "compacted_ops" add column "op_hash" text');
                break;
        }
    }
}
