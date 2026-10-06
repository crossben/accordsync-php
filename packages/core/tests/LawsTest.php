<?php

declare(strict_types=1);

namespace Accord\Core\Tests;

use Accord\Core\AddOp;
use Accord\Core\AssignOp;
use Accord\Core\Hlc;
use Accord\Core\IncOp;
use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Core\LocalWriter;
use Accord\Core\Op;
use Accord\Core\RecordSnapshot;
use Accord\Core\RemoveOp;
use Accord\Core\Replica;
use Accord\Core\Schema;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Strategy laws, checked against reference models. Port of `laws.test.ts`, with a seeded generator
 * in place of fast-check.
 *
 * Random devices write and partially sync. Every op ever made is then replayed into fresh replicas
 * in shuffled orders, with duplicates. The tests assert order independence and idempotency,
 * convergence, that each strategy matches its definition, and that compaction (snapshot, then the
 * remaining ops) reads the same as the full log.
 */
final class LawsTest extends TestCase
{
    private static function schema(): Schema
    {
        return Schema::define(['dossier' => ['name' => Schema::lww(), 'docs' => Schema::set(), 'visits' => Schema::counter(), 'status' => Schema::conflict()]]);
    }

    private static function runs(): int
    {
        $env = getenv('ACCORD_PROPERTY_RUNS');

        return \is_string($env) && ctype_digit($env) ? (int) $env : 300;
    }

    /** @return array{list<LocalWriter>, list<Op>} */
    private static function scenario(Randomizer $rnd): array
    {
        $tick = 1700000000000;
        $devs = [];
        for ($i = 0, $n = 2 + $rnd->getInt(0, 2); $i < $n; $i++) {
            // Phone clocks are wrong: each device runs up to an hour fast or slow.
            $skew = $rnd->getInt(-3600000, 3600000);
            $devs[] = new LocalWriter(self::schema(), "d$i", static function () use (&$tick, $skew): int {
                $tick += 7;

                return $tick + $skew;
            });
        }
        $all = [];
        for ($i = 0, $n = $rnd->getInt(0, 60); $i < $n; $i++) {
            if ($rnd->getInt(0, 1) === 1) {
                $w = $devs[$rnd->getInt(0, \count($devs) - 1)];
                $rec = 'dossier:' . $rnd->getInt(0, 1);
                $v = $rnd->getInt(-3, 3);
                $all[] = match ($rnd->getInt(0, 3)) {
                    0 => $w->assign($rec, 'name', "n$v"),
                    1 => $w->assign($rec, 'status', "s$v"),
                    2 => $w->inc($rec, 'visits', $v),
                    default => $rnd->getInt(0, 1) === 1
                        ? $w->remove($rec, 'docs', 'doc' . abs($v) % 3)
                        : $w->add($rec, 'docs', 'doc' . abs($v) % 3),
                };
            } else {
                // Deliver an arbitrary subset, in log order: partial syncs and lost messages.
                $from = $devs[$rnd->getInt(0, \count($devs) - 1)];
                $to = $devs[$rnd->getInt(0, \count($devs) - 1)];
                foreach ($from->replica()->ops() as $op) {
                    if ($rnd->getInt(0, 1) === 1) {
                        $to->receive($op);
                    }
                }
            }
        }

        return [$devs, $all];
    }

    /** @param iterable<Op> $ops */
    private static function replay(iterable $ops): Replica
    {
        return Support::replay(self::schema(), $ops);
    }

    /**
     * Reference model, computed straight from the definitions over the full op set.
     *
     * @param list<Op> $all
     */
    private static function model(array $all, string $record): string
    {
        $of = static fn(string $f): array => array_values(array_filter($all, static fn(Op $o) => $o->record === $record && $o->field === $f));

        $winner = null;
        foreach ($of('name') as $o) {
            self::assertInstanceOf(AssignOp::class, $o);
            if ($winner === null || Hlc::compare($o->hlc, $winner->hlc) > 0) {
                $winner = $o;
            }
        }
        $visits = 0;
        foreach ($of('visits') as $o) {
            self::assertInstanceOf(IncOp::class, $o);
            $visits += $o->by;
        }
        $removed = [];
        foreach ($of('docs') as $o) {
            self::assertTrue($o instanceof AddOp || $o instanceof RemoveOp);
            foreach ($o->deps as $d) {
                $removed[$d] = true;
            }
        }
        $docs = [];
        foreach ($of('docs') as $o) {
            if ($o instanceof AddOp && !isset($removed[$o->opId])) {
                $docs[(string) $o->element] = true;
            }
        }
        $docs = array_map(strval(...), array_keys($docs));
        sort($docs, SORT_STRING);
        $superseded = [];
        $statuses = $of('status');
        foreach ($statuses as $o) {
            self::assertInstanceOf(AssignOp::class, $o);
            foreach ($o->deps as $d) {
                $superseded[$d] = true;
            }
        }
        $live = array_values(array_filter($statuses, static fn(Op $o) => !isset($superseded[$o->opId])));
        usort($live, static fn(Op $a, Op $b) => strcmp($a->opId, $b->opId));

        $out = new JsonObject();
        if ($winner !== null) {
            $out->set('name', $winner->value);
        }
        $out->set('docs', $docs);
        $out->set('visits', $visits);
        if (\count($live) === 1) {
            self::assertInstanceOf(AssignOp::class, $live[0]);
            $out->set('status', new JsonObject(['value' => $live[0]->value]));
        } elseif (\count($live) > 1) {
            $out->set('status', new JsonObject(['conflicted' => array_map(
                static fn(Op $o) => new JsonObject(['value' => $o instanceof AssignOp ? $o->value : null, 'opId' => $o->opId]),
                $live,
            )]));
        }

        return Json::canonical($out);
    }

    public function testAnyDeliveryOrderWithDuplicatesReadsTheSameState(): void
    {
        for ($seed = 0; $seed < self::runs(); $seed++) {
            $rnd = new Randomizer(new Mt19937($seed));
            [, $all] = self::scenario($rnd);
            $reference = self::replay($all)->snapshot();
            for ($k = 0; $k < 3; $k++) {
                $withDuplicates = $rnd->shuffleArray([...$all, ...\array_slice($all, 0, $rnd->getInt(0, 4))]);
                self::assertSame($reference, self::replay($withDuplicates)->snapshot(), "seed $seed");
            }
        }
    }

    public function testDevicesConvergeOnceEveryOpIsDelivered(): void
    {
        for ($seed = 0; $seed < self::runs(); $seed++) {
            $rnd = new Randomizer(new Mt19937($seed));
            [$devs, $all] = self::scenario($rnd);
            foreach ($devs as $d) {
                foreach ($rnd->shuffleArray($all) as $op) {
                    $d->receive($op);
                }
            }
            $reference = self::replay($all)->snapshot();
            foreach ($devs as $d) {
                self::assertSame($reference, $d->replica()->snapshot(), "seed $seed");
            }
        }
    }

    public function testEachStrategyMatchesItsDefinition(): void
    {
        for ($seed = 0; $seed < self::runs(); $seed++) {
            $rnd = new Randomizer(new Mt19937($seed));
            [, $all] = self::scenario($rnd);
            $r = self::replay($rnd->shuffleArray($all));
            foreach ($r->records() as $record) {
                self::assertSame(self::model($all, $record), Json::canonical($r->read($record)), "seed $seed, $record");
            }
        }
    }

    public function testCompactionASnapshotPlusTheLaterOpsReadsLikeTheWholeLog(): void
    {
        for ($seed = 0; $seed < self::runs(); $seed++) {
            $rnd = new Randomizer(new Mt19937($seed));
            [, $all] = self::scenario($rnd);
            // Ops in creation order are causally ordered, so every prefix is causally closed.
            $cut = $rnd->getInt(0, \count($all));
            $before = self::replay(\array_slice($all, 0, $cut));
            $compacted = new Replica(self::schema());
            foreach ($before->records() as $record) {
                // Through JSON, as a snapshot travels from the server.
                $json = Json::canonical($before->snapshotRecord($record)->toJson());
                $compacted->loadSnapshot(RecordSnapshot::fromJson(Json::decode($json)));
            }
            foreach (\array_slice($all, $cut) as $op) {
                $compacted->apply($op);
            }
            self::assertSame(self::replay($all)->snapshot(), $compacted->snapshot(), "seed $seed, cut $cut");
        }
    }

    public function testAConflictFieldWrittenConcurrentlyIsNeverAutoResolved(): void
    {
        $rnd = new Randomizer(new Mt19937(7));
        for ($i = 0; $i < self::runs(); $i++) {
            $x = 'x' . $rnd->getInt(0, 999);
            $y = 'y' . $rnd->getInt(0, 999);
            $t = 0;
            $now = static function () use (&$t): int {
                return $t++;
            };
            $a = new LocalWriter(self::schema(), 'a', $now);
            $b = new LocalWriter(self::schema(), 'b', $now);
            $ops = [$a->assign('dossier:1', 'status', $x), $b->assign('dossier:1', 'status', $y)];
            $a->receive($ops[1]);
            $b->receive($ops[0]);
            foreach ([$a, $b] as $w) {
                self::assertSame([['record' => 'dossier:1', 'field' => 'status']], $w->replica()->conflicts());
            }
        }
    }
}
