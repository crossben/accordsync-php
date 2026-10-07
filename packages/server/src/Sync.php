<?php

declare(strict_types=1);

namespace Accord\Server;

use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Core\Op;
use Accord\Core\RecordSnapshot;
use Accord\Core\Replica;
use Accord\Core\Utf16;
use Accord\Core\Wire;

/**
 * Push, pull and device tracking. A faithful port of `sync.ts`: same SQL, same locks, same order
 * of checks (ADR-0010).
 */
final class Sync
{
    /**
     * Every push takes this transaction-scoped lock in shared mode; compaction takes it exclusively,
     * so the ops being folded cannot change underneath (ADR-0007, ADR-0010).
     */
    public const int FEED_LOCK = 0x4acc0d;

    /** Pull retries after a serialization failure (40001) before giving up. */
    private const int PULL_ATTEMPTS = 20;

    /** @param \Closure(): int $now physical time in ms */
    public function __construct(
        private readonly ServerDefinition $def,
        private readonly Database $db,
        private readonly \Closure $now,
    ) {}

    /**
     * Registers the device to this user on first sight; refuses a device id owned by someone else.
     * A device seen again after the retirement TTL is flagged: its next pull must start from zero,
     * because compaction may have folded away ops it never received (ADR-0005).
     */
    public function touchDevice(Caller $caller): void
    {
        $row = $this->db->transaction(fn(): ?array => $this->db->one(
            'insert into devices (device_id, sub, read_keys) values (?, ?, null)
             on conflict (device_id) do update set last_seen = now(),
               needs_resync = devices.needs_resync or devices.last_seen < now() - make_interval(secs => ?::double precision)
             where devices.sub = ? returning sub',
            [$caller->deviceId, $caller->sub, self::num($this->def->compaction->deviceTtlMs() / 1000), $caller->sub],
        ), asyncCommit: true);
        if ($row === null) {
            throw new Forbidden("device {$caller->deviceId} belongs to another user");
        }
    }

    /**
     * @param list<mixed> $raw the pushed ops, as decoded JSON (core value model)
     *
     * @return array{acked: list<string>, refused: list<array{op_id: string, reason: string}>}
     */
    public function push(Caller $caller, array $raw): array
    {
        $maxOps = $this->def->limits->maxPushOps;
        if (\count($raw) > $maxOps) {
            throw new BadRequest("at most $maxOps ops per push");
        }
        $maxSkewMs = $this->def->limits->maxSkewMs;

        $acked = [];
        $refused = [];
        /** @var array<string, list<Op>> $byRecord */
        $byRecord = [];

        foreach ($raw as $input) {
            try {
                $op = Wire::decode($input);
                // PostgreSQL cannot store a lone surrogate (jsonb refuses it, text replaces it): refuse
                // the op instead of failing the whole push.
                $bad = self::lonePath($input);
                if ($bad !== null) {
                    throw new \UnexpectedValueException("lone surrogate in $bad");
                }
            } catch (\Throwable $e) {
                $opId = $input instanceof JsonObject ? $input->get('op_id') : null;
                if (!\is_string($opId)) {
                    throw new BadRequest('malformed op: ' . $e->getMessage());
                }
                $refused[] = ['op_id' => $opId, 'reason' => 'malformed op: ' . $e->getMessage()];
                continue;
            }
            if ($op->hlc->node !== $caller->deviceId) {
                $refused[] = ['op_id' => $op->opId, 'reason' => "op belongs to device {$op->hlc->node}"];
                continue;
            }
            $byRecord["\0" . $op->record][] = $op; // prefixed: a PHP array would turn "10" into 10
        }

        // Pushes run concurrently. Each takes the feed lock in shared mode (only compaction takes it
        // exclusively) and locks the rows of the records it writes, in sorted order so two pushes can
        // never deadlock. Pulls stay correct because they read the feed in transaction order and only
        // up to the oldest transaction still running (ADR-0010). PHP serves one request per worker,
        // so the worker pool bounds concurrent pushes (ADR-P06).
        $this->db->transaction(function () use ($caller, $raw, $byRecord, $maxSkewMs, &$acked, &$refused): void {
            $db = $this->db;
            $db->run('select pg_advisory_xact_lock_shared(?::bigint)', [self::FEED_LOCK]);
            // Op numbers this device already used (read once: within a batch, ops apply out of order).
            $usedUpTo = Database::int($db->one('select max_op_seq from devices where device_id = ?', [$caller->deviceId])['max_op_seq'] ?? 0);
            $maxApplied = 0;
            $now = ($this->now)();
            $records = array_map(static fn(string|int $k): string => substr((string) $k, 1), array_keys($byRecord));
            usort($records, Utf16::compare(...)); // JavaScript's default sort

            // Create missing record rows, so every record can be locked; a row created here and left
            // unused (every op refused) is removed again below.
            $created = [];
            if ($records !== []) {
                $rows = $db->all(
                    "insert into records (record, scopes, state)
                     select r, '{}'::text[], null from unnest(?::text[]) with ordinality as t(r, i) order by i
                     on conflict (record) do nothing returning record",
                    [Database::textArray($records)],
                );
                foreach ($rows as $r) {
                    $created["\0" . Database::str($r['record'])] = true;
                }
            }
            $locked = [];
            foreach ($db->all(
                'select record, to_json(scopes) as scopes, state from records where record = any(?::text[]) order by record for update',
                [Database::textArray($records === [] ? [''] : $records)],
            ) as $r) {
                $locked["\0" . Database::str($r['record'])] = $r;
            }

            foreach ($records as $record) {
                $isNew = isset($created["\0" . $record]);
                $existing = $locked["\0" . $record] ?? throw new \RuntimeException("record row $record is missing");
                if ($existing['state'] !== null) {
                    $replica = new Replica($this->def->schema);
                    $replica->loadSnapshot(RecordSnapshot::fromJson(Json::decode(Database::str($existing['state']))));
                } else {
                    // A new record, or one written before migration 0004: rebuild from the feed.
                    $replica = self::loadRecord($db, $this->def, $record);
                }
                $pending = $byRecord["\0" . $record];
                $ids = Database::textArray(array_map(static fn(Op $o): string => $o->opId, $pending));
                // Already applied (in the feed) or folded by compaction: acknowledge, never apply twice.
                // Checked after taking the record lock, so a concurrent retry of the same op is seen.
                // An id already in the feed is a retry only if it is the same op; the same id with
                // other content means the device reused an id (lost storage) and must be refused.
                $stored = [];
                foreach ($db->all('select op_id, op from feed where op_id = any(?::text[])', [$ids]) as $r) {
                    $stored["\0" . Database::str($r['op_id'])] = Json::canonical(Json::decode(Database::str($r['op'])));
                }
                // Folded ops keep a hash of their content (migration 0006): null only for rows folded
                // before it, which are acknowledged as before.
                $compacted = [];
                foreach ($db->all('select op_id, op_hash from compacted_ops where op_id = any(?::text[])', [$ids]) as $r) {
                    $compacted["\0" . Database::str($r['op_id'])] = $r['op_hash'];
                }
                /** @var ?list<string> $scopes */
                $scopes = $isNew ? null : Database::stringList($existing['scopes']);
                $changed = false;
                $rows = [];

                foreach ($pending as $op) {
                    $key = "\0" . $op->opId;
                    $wire = Wire::encode($op);
                    $previous = $stored[$key] ?? null;
                    if ($previous !== null && $previous !== Json::canonical($wire)) {
                        $refused[] = ['op_id' => $op->opId, 'reason' => "op id already used: {$op->opId} names another op"];
                        continue;
                    }
                    $folded = $compacted[$key] ?? null;
                    if (\is_string($folded) && $folded !== self::opHash($wire)) {
                        $refused[] = ['op_id' => $op->opId, 'reason' => "op id already used: {$op->opId} names another op"];
                        continue;
                    }
                    if ($previous !== null || \array_key_exists($key, $compacted) || $replica->has($op->opId)) {
                        $acked[] = $op->opId; // a retried push: already applied
                        continue;
                    }
                    $opSeq = Op::parseId($op->opId)['seq'];
                    if ($opSeq <= $usedUpTo) {
                        // Not a retry (that would be in the feed): the device reused an op id,
                        // typically after losing its storage. Refuse it loudly.
                        $refused[] = ['op_id' => $op->opId, 'reason' => "op id already used: this device's ops are numbered above $usedUpTo"];
                        continue;
                    }
                    $reason = $this->check($caller, $replica, $scopes, $op, $now, $maxSkewMs);
                    if ($reason !== null) {
                        $refused[] = ['op_id' => $op->opId, 'reason' => $reason];
                        continue;
                    }
                    $replica->apply($op);
                    try {
                        $next = $this->scopesOf($replica, $record);
                    } catch (\Throwable $e) {
                        throw new \RuntimeException("scope function failed for $record: {$e->getMessage()}", 0, $e);
                    }
                    // A scope change goes in before the op that caused it: a device the record is
                    // entering receives the history (snapshot and older ops) first, then this op.
                    if ($scopes === null || !self::sameKeys($scopes, $next)) {
                        $rows[] = ['scope', $record, null, null, $next, $scopes ?? []];
                    }
                    $rows[] = ['op', $record, $op->opId, Json::canonical($wire), $next, null];
                    $scopes = $next;
                    $changed = true;
                    $acked[] = $op->opId;
                    $maxApplied = max($maxApplied, $opSeq);
                }
                if ($changed) {
                    // One insert per record: rows keep this order (same transaction, increasing seq).
                    $values = [];
                    $params = [];
                    foreach ($rows as [$kind, $rec, $opId, $opJson, $after, $before]) {
                        $values[] = '(?, ?, ?, ?::jsonb, ?::text[], ?::text[])';
                        array_push($params, $kind, $rec, $opId, $opJson, Database::textArray($after), $before === null ? null : Database::textArray($before));
                    }
                    $db->run('insert into feed (kind, record, op_id, op, scopes, scopes_before) values ' . implode(', ', $values), $params);
                    $db->run(
                        'update records set scopes = ?::text[], state = ?::jsonb where record = ?',
                        [Database::textArray($scopes ?? []), Json::canonical($replica->snapshotRecord($record)->toJson()), $record],
                    );
                } elseif ($isNew) {
                    $db->run('delete from records where record = ?', [$record]);
                }
            }

            // The device has its answers for every op below the first one it sent now (clients push
            // their outbox in order): compacted-op entries below that can be forgotten.
            $seqs = [];
            $prefix = $caller->deviceId . ':';
            foreach ($raw as $r) {
                $id = $r instanceof JsonObject ? $r->get('op_id') : null;
                if (\is_string($id) && str_starts_with($id, $prefix)) {
                    $n = self::jsNumber(substr($id, \strlen($prefix)));
                    if ($n !== null && self::isSafeInteger($n)) {
                        $seqs[] = (int) $n;
                    }
                }
            }
            if ($seqs !== []) {
                $db->run(
                    'update devices set push_floor = greatest(push_floor, ?::bigint), max_op_seq = greatest(max_op_seq, ?::bigint) where device_id = ?',
                    [min($seqs), $maxApplied, $caller->deviceId],
                );
            }
        });

        return ['acked' => $acked, 'refused' => $refused];
    }

    /**
     * Why `$op` must be refused, or null to accept it.
     *
     * @param ?list<string> $scopes
     */
    private function check(Caller $caller, Replica $replica, ?array $scopes, Op $op, int $now, int $maxSkewMs): ?string
    {
        try {
            $replica->validate($op);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        if ($op->hlc->wall - $now > $maxSkewMs) {
            return 'clock is ' . ($op->hlc->wall - $now) . " ms ahead of the server (limit $maxSkewMs ms)";
        }
        // An existing record: the caller must be allowed to write where it is now. A new record:
        // where the write puts it.
        $where = $scopes;
        if ($where === null) {
            $fresh = new Replica($this->def->schema);
            $fresh->apply($op);
            try {
                $where = $this->scopesOf($fresh, $op->record);
            } catch (\Throwable $e) {
                return 'scope function failed: ' . $e->getMessage();
            }
        }
        if (!self::overlaps($where, $caller->write)) {
            return "out of scope: you may not write {$op->record}";
        }

        return null;
    }

    /**
     * @return array{resync_required: true}|array{items: list<JsonObject>, cursor: int, has_more: bool, device_seq: int}
     */
    public function pull(Caller $caller, int $cursor, int $requested): array
    {
        $limit = max(1, min($requested, $this->def->limits->maxPullLimit));
        $read = self::normalize($caller->read);

        // Two pulls from one device at once can collide on its `devices` row under repeatable read
        // (PostgreSQL error 40001). The pull only reads the feed, so running it again is always safe.
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->db->transaction(fn(): array => $this->pullOnce($caller, $cursor, $limit, $read), 'repeatable read');
            } catch (\PDOException $e) {
                // Each round of colliding pulls lets at least one through, so this always ends; the
                // jitter keeps retries from colliding again in lockstep.
                if ($e->getCode() !== '40001' || $attempt >= self::PULL_ATTEMPTS) {
                    throw $e;
                }
                usleep((int) (mt_rand() / mt_getrandmax() * 10_000 * $attempt));
            }
        }
    }

    /**
     * @param list<string> $read
     *
     * @return array{resync_required: true}|array{items: list<JsonObject>, cursor: int, has_more: bool, device_seq: int}
     */
    private function pullOnce(Caller $caller, int $cursor, int $limit, array $read): array
    {
        $db = $this->db;
        $device = $db->one('select to_json(read_keys) as read_keys, needs_resync, max_op_seq, to_json(delta_keys) as delta_keys, delta_cursor from devices where device_id = ?', [$caller->deviceId])
            ?? throw new \RuntimeException('no result');
        if ($cursor > 0 && self::bool($device['needs_resync'])) {
            return ['resync_required' => true];
        }
        // Read scopes changed (new claims): send what entered and what left, instead of everything.
        // A delta stays pending until the device pulls from a cursor above the one it was sent from
        // (it then has the answer). A pull at or below that cursor is a retry of a lost answer: the
        // delta is computed again from the keys the device had before it (ADR-0011, 2026-10-07).
        $pendingKeys = Database::stringList($device['delta_keys']);
        $pendingCursor = $device['delta_cursor'] === null ? null : Database::int($device['delta_cursor']);
        $pending = $pendingKeys !== null && $pendingCursor !== null;
        $retry = $cursor > 0 && $pending && $cursor <= $pendingCursor;
        $before = $retry ? $pendingKeys : (Database::stringList($device['read_keys']) ?? []);
        $keysChanged = $cursor > 0 && !self::sameKeys($before, $read);
        $delta = null;
        if ($keysChanged) {
            $delta = $this->scopeDelta($before, $read, $this->def->limits->maxScopeDelta);
            if ($delta === null) {
                return ['resync_required' => true];
            }
        }
        // The device has applied everything up to `cursor`: compaction may fold ops below it.
        if ($cursor === 0) {
            $db->run("update devices set read_keys = ?::text[], needs_resync = false, cursor = '0', delta_keys = null, delta_cursor = null where device_id = ?", [Database::textArray($read), $caller->deviceId]);
        } elseif ($keysChanged) {
            $db->run(
                'update devices set cursor = greatest(cursor, ?::bigint), read_keys = ?::text[], delta_keys = ?::text[], delta_cursor = ?::bigint where device_id = ?',
                [$cursor, Database::textArray($read), Database::textArray($before), $retry ? $pendingCursor : $cursor, $caller->deviceId],
            );
        } elseif ($pending) {
            $db->run(
                'update devices set cursor = greatest(cursor, ?::bigint), read_keys = ?::text[], delta_keys = null, delta_cursor = null where device_id = ?',
                [$cursor, Database::textArray($read), $caller->deviceId],
            );
        } else {
            $db->run('update devices set cursor = greatest(cursor, ?::bigint) where device_id = ?', [$cursor, $caller->deviceId]);
        }

        // Rows from transactions older than every transaction still running are final: nothing can
        // ever be inserted below this horizon (ADR-0010).
        $horizon = Database::int($db->one('select accord_horizon() as h')['h'] ?? 0);
        $readArray = Database::textArray($read);
        $visible = "((kind in ('op', 'snapshot') and scopes && ?::text[])
            or (kind = 'scope' and (scopes_before && ?::text[]) <> (scopes && ?::text[])))";
        $fetched = $db->all(
            "select seq, pos, kind, record, op_id, op, to_json(scopes) as scopes from feed
             where pos > ?::bigint and pos < ?::bigint and $visible order by pos, seq limit ?::bigint",
            [$cursor, $horizon, $readArray, $readArray, $readArray, $limit + 1],
        );

        // A page ends on a transaction boundary, because the cursor is a transaction position. If one
        // transaction alone is bigger than a page, it is sent whole.
        $rows = $fetched;
        $full = false;
        if (\count($fetched) > $limit) {
            $full = true;
            $lastPos = Database::int($fetched[$limit]['pos']);
            $rows = array_values(array_filter(\array_slice($fetched, 0, $limit), static fn(array $r): bool => Database::int($r['pos']) !== $lastPos));
            if ($rows === []) {
                $rows = $db->all(
                    "select seq, pos, kind, record, op_id, op, to_json(scopes) as scopes from feed
                     where pos = ?::bigint and $visible order by seq",
                    [$lastPos, $readArray, $readArray, $readArray],
                );
            }
        }

        $items = [];
        $sent = [];
        $send = static function (array $row) use (&$items, &$sent): void {
            $value = Json::decode(Database::str($row['op']));
            if ($row['kind'] === 'snapshot') {
                $items[] = new JsonObject(['type' => 'snapshot', 'snapshot' => $value]);

                return;
            }
            $opId = $value instanceof JsonObject ? Database::str($value->get('op_id')) : '';
            if (isset($sent["\0" . $opId])) {
                return;
            }
            $sent["\0" . $opId] = true;
            $items[] = new JsonObject(['type' => 'op', 'op' => $value]);
        };
        if ($delta !== null) {
            foreach ($delta['history'] as $h) {
                $send($h);
            }
            foreach ($delta['exits'] as $record) {
                $items[] = new JsonObject(['type' => 'exit', 'record' => $record]);
            }
        }
        foreach ($rows as $row) {
            if ($row['kind'] === 'op' || $row['kind'] === 'snapshot') {
                $send($row);
                continue;
            }
            if (self::overlaps(Database::stringList($row['scopes']) ?? [], $read)) {
                // The record entered the caller's scope: send its whole history up to this point.
                $history = $db->all(
                    "select kind, op from feed where record = ? and kind in ('op', 'snapshot')
                     and (pos, seq) < (?::bigint, ?::bigint) order by pos, seq",
                    [Database::str($row['record']), Database::int($row['pos']), Database::int($row['seq'])],
                );
                foreach ($history as $h) {
                    $send($h);
                }
            } else {
                $items[] = new JsonObject(['type' => 'exit', 'record' => Database::str($row['record'])]);
            }
        }

        // A full page ends at its last transaction; otherwise everything below the horizon was read.
        $next = $full ? Database::int($rows[\count($rows) - 1]['pos']) : $horizon - 1;

        return [
            'items' => $items,
            'cursor' => max($cursor, $next),
            'has_more' => $full,
            'device_seq' => Database::int($device['max_op_seq']),
        ];
    }

    /**
     * What a change of read keys means for a device: the history of every record now visible that
     * was not visible before, and an exit for every record no longer visible at all. Null when the
     * change touches more than `$max` records: a full resync is cheaper then.
     *
     * @param list<string> $before
     * @param list<string> $after
     *
     * @return ?array{history: list<array<string, mixed>>, exits: list<string>}
     */
    private function scopeDelta(array $before, array $after, int $max): ?array
    {
        $was = Database::textArray($before);
        $now = Database::textArray($after);
        $entering = $this->db->all(
            'select record from records where scopes && ?::text[] and not (scopes && ?::text[]) limit ?::bigint',
            [$now, $was, $max + 1],
        );
        $leaving = $this->db->all(
            'select record from records where scopes && ?::text[] and not (scopes && ?::text[]) limit ?::bigint',
            [$was, $now, $max + 1],
        );
        if (\count($entering) + \count($leaving) > $max) {
            return null;
        }
        $history = $entering === [] ? [] : $this->db->all(
            "select kind, op from feed where record = any(?::text[]) and kind in ('op', 'snapshot') order by record, pos, seq",
            [Database::textArray(array_map(static fn(array $r): string => Database::str($r['record']), $entering))],
        );

        return ['history' => $history, 'exits' => array_map(static fn(array $r): string => Database::str($r['record']), $leaving)];
    }

    /** A record's state on the server: its latest snapshot, then the ops after it. */
    public static function loadRecord(Database $db, ServerDefinition $def, string $record): Replica
    {
        $replica = new Replica($def->schema);
        $rows = $db->all("select kind, op from feed where record = ? and kind in ('op', 'snapshot') order by pos, seq", [$record]);
        foreach ($rows as $row) {
            $value = Json::decode(Database::str($row['op']));
            if ($row['kind'] === 'snapshot') {
                $replica->loadSnapshot(RecordSnapshot::fromJson($value));
            } else {
                $replica->apply(Wire::decode($value));
            }
        }

        return $replica;
    }

    /** @return list<string> */
    private function scopesOf(Replica $replica, string $record): array
    {
        $type = Op::recordType($record);
        $fn = $this->def->scopes[$type] ?? throw new \RuntimeException("no scope function for $type");
        $fields = [];
        foreach ($replica->read($record) ?? new JsonObject() as $field => $value) {
            $fields[$field] = $value;
        }
        $keys = [];
        foreach ($fn(new ScopedRecord($record, $fields)) as $k) {
            $keys[] = \is_string($k) ? $k : throw new \UnexpectedValueException('scope keys must be strings');
        }

        return self::normalize($keys);
    }

    /**
     * Unique, sorted by UTF-16 code unit (JavaScript's default sort).
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    public static function normalize(array $keys): array
    {
        $unique = [];
        foreach ($keys as $k) {
            $unique["\0" . $k] = $k;
        }
        $out = array_values($unique);
        usort($out, Utf16::compare(...));

        return $out;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function overlaps(array $a, array $b): bool
    {
        return array_intersect($a, $b) !== [];
    }

    /**
     * Where a lone surrogate hides in a JSON value (a key or a string), or null if nowhere. Strings
     * from the core's parser are WTF-8 (ADR-P04): a lone surrogate is the 3-byte sequence
     * ED A0..BF xx, which valid UTF-8 never contains. Keys are visited in JavaScript's
     * `Object.entries` order (array-index keys first, ascending), so the path matches the TypeScript
     * server's.
     */
    public static function lonePath(mixed $value, string $path = 'op'): ?string
    {
        if (\is_string($value)) {
            return self::lone($value) ? $path : null;
        }
        if (\is_array($value)) {
            foreach (array_values($value) as $i => $v) {
                $found = self::lonePath($v, "{$path}[$i]");
                if ($found !== null) {
                    return $found;
                }
            }
        } elseif ($value instanceof JsonObject) {
            $keys = $value->keys();
            $index = array_values(array_filter($keys, self::isArrayIndex(...)));
            usort($index, static fn(string $a, string $b): int => (int) $a <=> (int) $b);
            foreach ([...$index, ...array_values(array_filter($keys, static fn(string $k): bool => !self::isArrayIndex($k)))] as $k) {
                if (self::lone($k)) {
                    return "$path (a key)";
                }
                $found = self::lonePath($value->get($k), "$path.$k");
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private static function lone(string $s): bool
    {
        return preg_match('/\xED[\xA0-\xBF]/', $s) === 1;
    }

    /** A canonical array index, as JavaScript orders object keys: "0" … "4294967294". */
    private static function isArrayIndex(string $k): bool
    {
        return preg_match('/^(0|[1-9][0-9]{0,9})$/', $k) === 1 && (int) $k < 4294967295;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function sameKeys(array $a, array $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    /**
     * The hash a compacted op is remembered by: SHA-256, lowercase hex, of the UTF-8 canonical JSON
     * of its wire form. Every server implementation computes it the same way (it is stored).
     */
    public static function opHash(JsonObject $wireOp): string
    {
        return hash('sha256', Json::canonical($wireOp));
    }

    /**
     * JavaScript's `Number(string)` for the strings HTTP gives us: null for NaN.
     */
    public static function jsNumber(string $s): ?float
    {
        $t = trim($s, " \t\n\r\v\f");
        if ($t === '') {
            return 0.0;
        }
        if (preg_match('/^0[xX][0-9a-fA-F]+$/D', $t) === 1) {
            return (float) hexdec(substr($t, 2));
        }
        if (preg_match('/^0[oO][0-7]+$/D', $t) === 1) {
            return (float) octdec(substr($t, 2));
        }
        if (preg_match('/^0[bB][01]+$/D', $t) === 1) {
            return (float) bindec(substr($t, 2));
        }
        if (preg_match('/^[+-]?(Infinity|(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?)$/D', $t) !== 1) {
            return null;
        }
        if (str_contains($t, 'Infinity')) {
            return str_starts_with($t, '-') ? -INF : INF;
        }

        return (float) $t;
    }

    public static function isSafeInteger(float $n): bool
    {
        return is_finite($n) && floor($n) === $n && abs($n) <= Json::MAX_SAFE_INTEGER;
    }

    private static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1';
    }

    private static function num(float $n): string
    {
        return Json::number($n);
    }
}
