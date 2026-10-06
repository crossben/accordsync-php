<?php

declare(strict_types=1);

namespace Accord\Server;

use Accord\Core\Json;
use Accord\Core\Op;
use Accord\Core\Wire;

/**
 * Folds the history of records that every live device already has into one snapshot row per
 * record (ADR-0005, ADR-0008). Port of `compact.ts`. The snapshot takes the position of the last op
 * it replaces, so live devices, whose cursors are past it, never receive it; devices starting from
 * zero, and records entering someone's scope, get the snapshot instead of the old ops.
 */
final class Compactor
{
    private const int INSERT_CHUNK = 1000;

    public function __construct(private readonly ServerDefinition $def, private readonly Database $db) {}

    public function run(): CompactionResult
    {
        $minOps = $this->def->compaction->minOps;
        $ttlSecs = Json::number($this->def->compaction->deviceTtlMs() / 1000);
        $db = $this->db;

        return $db->transaction(function () use ($db, $minOps, $ttlSecs): CompactionResult {
            // Exclusive: waits for running pushes (they hold the lock shared) and holds new ones
            // back, so the ops being folded cannot change underneath (ADR-0010).
            $db->run('select pg_advisory_xact_lock(?::bigint)', [Sync::FEED_LOCK]);
            $db->run("select set_config('accord.compaction', 'on', true)");

            $watermark = Database::int($db->one(
                'select coalesce(
                   (select min(cursor) from devices where last_seen > now() - make_interval(secs => ?::double precision)),
                   accord_horizon() - 1) as w',
                [$ttlSecs],
            )['w'] ?? null);

            $candidates = $db->all(
                "select record, max(pos) as last from feed where kind in ('op', 'snapshot') group by record
                 having max(pos) <= ?::bigint and count(*) filter (where kind = 'op') >= ?::bigint",
                [$watermark, $minOps],
            );

            $opsFolded = 0;
            foreach ($candidates as $c) {
                $record = Database::str($c['record']);
                $last = Database::int($c['last']);
                $replica = Sync::loadRecord($db, $this->def, $record);
                $ops = $replica->ops();
                $scopes = $db->one('select to_json(scopes) as scopes from records where record = ?', [$record])
                    ?? throw new \RuntimeException("no record row for $record");
                foreach (array_chunk($ops, self::INSERT_CHUNK) as $chunk) {
                    $values = [];
                    $params = [];
                    foreach ($chunk as $op) {
                        $id = Op::parseId($op->opId);
                        $values[] = '(?, ?, ?::bigint, ?)';
                        array_push($params, $op->opId, $id['device'], $id['seq'], Sync::opHash(Wire::encode($op)));
                    }
                    $db->run('insert into compacted_ops (op_id, device, op_seq, op_hash) values ' . implode(', ', $values) . ' on conflict do nothing', $params);
                }
                $db->run('delete from feed where record = ? and pos <= ?::bigint', [$record, $last]);
                // The snapshot takes the position of the last op it replaces: live devices are past it.
                $db->run(
                    "insert into feed (pos, kind, record, op, scopes) values (?::bigint, 'snapshot', ?, ?::jsonb, ?::text[])",
                    [$last, $record, Json::canonical($replica->snapshotRecord($record)->toJson()), Database::textArray(Database::stringList($scopes['scopes']) ?? [])],
                );
                $opsFolded += \count($ops);
            }
            // Entries a device can no longer retry (it has pushed past them) are no longer needed.
            $pruned = $db->run('delete from compacted_ops c using devices d where c.device = d.device_id and c.op_seq < d.push_floor')->rowCount();

            return new CompactionResult($watermark, \count($candidates), $opsFolded, $pruned);
        });
    }
}
