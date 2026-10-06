<?php

declare(strict_types=1);

namespace Accord\Core\Tests;

use Accord\Core\AccordException;
use Accord\Core\ApplyResult;
use Accord\Core\ClockSkewException;
use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Core\LocalWriter;
use Accord\Core\Schema;
use PHPUnit\Framework\TestCase;

/** Port of `writer.test.ts`. */
final class WriterTest extends TestCase
{
    private static function schema(): Schema
    {
        return Schema::define([
            'dossier' => ['client_name' => Schema::lww(), 'documents' => Schema::set(), 'visits' => Schema::counter(), 'status' => Schema::conflict()],
        ]);
    }

    private static function device(string $id, int $start = 1000): LocalWriter
    {
        $now = $start;

        return new LocalWriter(self::schema(), $id, static function () use (&$now): int {
            return $now++;
        });
    }

    private static function field(LocalWriter $w, string $record, string $field): mixed
    {
        $r = $w->replica()->read($record);
        self::assertNotNull($r);

        return $r->get($field);
    }

    private static function assertReads(string $json, mixed $value): void
    {
        self::assertSame($json, Json::canonical($value));
    }

    private static function assertThrowsMatching(string $pattern, callable $f): void
    {
        try {
            $f();
            self::fail("no exception, expected /$pattern/");
        } catch (AccordException $e) {
            self::assertMatchesRegularExpression("/$pattern/", $e->getMessage());
        }
    }

    public function testNumbersOpsPerDeviceAndStampsIncreasingClocks(): void
    {
        $a = self::device('a');
        $o1 = $a->assign('dossier:1', 'client_name', 'Awa');
        $o2 = $a->assign('dossier:1', 'client_name', 'Awa Diop');
        self::assertSame(['a:1', 'a:2'], [$o1->opId, $o2->opId]);
        self::assertSame(1, \Accord\Core\Hlc::compare($o2->hlc, $o1->hlc));
        self::assertSame('Awa Diop', self::field($a, 'dossier:1', 'client_name'));
    }

    public function testReadsDefaultsForUntouchedFieldsAndLeavesNeverWrittenOnesOut(): void
    {
        $a = self::device('a');
        $a->inc('dossier:1', 'visits', 2);
        self::assertReads('{"documents":[],"visits":2}', $a->replica()->read('dossier:1'));
        self::assertNull($a->replica()->read('dossier:404'));
    }

    public function testAFieldAssignedNullReadsAsNull(): void
    {
        $a = self::device('a');
        $a->assign('dossier:1', 'client_name', null);
        $r = $a->replica()->read('dossier:1');
        self::assertNotNull($r);
        self::assertTrue($r->has('client_name'));
        self::assertNull($r->get('client_name'));
    }

    public function testRejectsWritesThatDoNotMatchTheSchema(): void
    {
        $a = self::device('a');
        self::assertThrowsMatching('unknown field', static fn() => $a->assign('dossier:1', 'nope', 1));
        self::assertThrowsMatching('unknown record type', static fn() => $a->assign('ghost:1', 'x', 1));
        self::assertThrowsMatching('lww', static fn() => $a->inc('dossier:1', 'client_name', 1));
        self::assertThrowsMatching('string or number', static fn() => $a->add('dossier:1', 'documents', new JsonObject(['a' => 1])));
        self::assertThrowsMatching('string or number', static fn() => $a->add('dossier:1', 'documents', INF));
        self::assertThrowsMatching('JsonObject', static fn() => $a->assign('dossier:1', 'client_name', ['a' => 1]));
    }

    public function testSetsRemoveOnlyRemovesWhatTheWriterHasSeen(): void
    {
        $a = self::device('a');
        $b = self::device('b');
        $add = $a->add('dossier:1', 'documents', 'cni.pdf');
        $b->receive($add);
        $remove = $b->remove('dossier:1', 'documents', 'cni.pdf');
        $readd = $a->add('dossier:1', 'documents', 'cni.pdf'); // concurrent with the remove
        $a->receive($remove);
        $b->receive($readd);
        self::assertSame(['cni.pdf'], self::field($a, 'dossier:1', 'documents'));
        self::assertSame(['cni.pdf'], self::field($b, 'dossier:1', 'documents'));
    }

    public function testSetsOneAndOnePointZeroAreTheSameElementAsInJavaScript(): void
    {
        $a = self::device('a');
        $a->add('dossier:1', 'documents', 1);
        $a->add('dossier:1', 'documents', '1');
        $a->remove('dossier:1', 'documents', 1.0);
        self::assertSame(['1'], self::field($a, 'dossier:1', 'documents'));
    }

    public function testSetsReadOneAndOnePointZeroAddedConcurrentlyAsOneElement(): void
    {
        $a = self::device('a');
        $b = self::device('b');
        $a->receive($b->add('dossier:1', 'documents', 1.0));
        $a->add('dossier:1', 'documents', 2);
        $b2 = self::device('c');
        $a->receive($b2->add('dossier:1', 'documents', 1));
        self::assertReads('[1,2]', self::field($a, 'dossier:1', 'documents'));
    }

    public function testConflictConcurrentAssignsSurfaceBothValues(): void
    {
        $a = self::device('a');
        $b = self::device('b');
        $x = $a->assign('dossier:1', 'status', 'approved');
        $y = $b->assign('dossier:1', 'status', 'rejected');
        $a->receive($y);
        $b->receive($x);
        foreach ([$a, $b] as $w) {
            self::assertReads('{"conflicted":[{"opId":"a:1","value":"approved"},{"opId":"b:1","value":"rejected"}]}', self::field($w, 'dossier:1', 'status'));
            self::assertSame([['record' => 'dossier:1', 'field' => 'status']], $w->replica()->conflicts());
        }
    }

    public function testConflictASequentialEditReplacesTheValueItSaw(): void
    {
        $a = self::device('a');
        $b = self::device('b');
        $b->receive($a->assign('dossier:1', 'status', 'draft'));
        $a->receive($b->assign('dossier:1', 'status', 'submitted'));
        self::assertReads('{"value":"submitted"}', self::field($a, 'dossier:1', 'status'));
        self::assertSame([], $a->replica()->conflicts());
    }

    public function testConflictResolvingKeepsAnEditTheResolverHadNotSeen(): void
    {
        $a = self::device('a');
        $b = self::device('b');
        $c = self::device('c');
        $x = $a->assign('dossier:1', 'status', 'approved');
        $y = $b->assign('dossier:1', 'status', 'rejected');
        $z = $c->assign('dossier:1', 'status', 'on_hold'); // c is offline the whole time
        $a->receive($y);
        $resolution = $a->assign('dossier:1', 'status', 'approved'); // resolves x and y
        self::assertSame(['a:1', 'b:1'], $resolution->deps);
        foreach ([$resolution, $z, $x] as $op) {
            $b->receive($op);
        }
        self::assertReads('{"conflicted":[{"opId":"a:2","value":"approved"},{"opId":"c:1","value":"on_hold"}]}', self::field($b, 'dossier:1', 'status'));
    }

    public function testCountsEveryIncrementIncludingNegativeOnes(): void
    {
        $a = self::device('a');
        $b = self::device('b');
        $ops = [$a->inc('dossier:1', 'visits', 3), $b->inc('dossier:1', 'visits', -1)];
        $a->receive($ops[1]);
        $b->receive($ops[0]);
        self::assertSame(2, self::field($a, 'dossier:1', 'visits'));
        self::assertSame(2, self::field($b, 'dossier:1', 'visits'));
    }

    public function testIgnoresADuplicateOp(): void
    {
        $a = self::device('a');
        $b = self::device('b');
        $op = $a->inc('dossier:1', 'visits', 5);
        self::assertSame(ApplyResult::Applied, $b->receive($op));
        self::assertSame(ApplyResult::Duplicate, $b->receive($op));
        self::assertSame(5, self::field($b, 'dossier:1', 'visits'));
    }

    public function testLwwHighestClockWinsRegardlessOfArrivalOrder(): void
    {
        $a = self::device('a', 1000);
        $b = self::device('b', 5000); // b's clock is ahead
        $x = $b->assign('dossier:1', 'client_name', 'from b');
        $y = $a->assign('dossier:1', 'client_name', 'from a');
        $a->receive($x);
        $b->receive($y);
        self::assertSame('from b', self::field($a, 'dossier:1', 'client_name'));
        self::assertSame('from b', self::field($b, 'dossier:1', 'client_name'));
    }

    public function testRefusesAnOpFromAClockTooFarAheadAndLeavesStateUntouched(): void
    {
        $a = new LocalWriter(self::schema(), 'a', static fn(): int => 1000, 60000);
        $liar = new LocalWriter(self::schema(), 'liar', static fn(): int => 10000000);
        try {
            $a->receive($liar->inc('dossier:1', 'visits', 1));
            self::fail('accepted a clock from the future');
        } catch (ClockSkewException) {
            self::assertNull($a->replica()->read('dossier:1'));
        }
    }

    public function testDiscardRollsBackARefusedOpAndKeepsTheRest(): void
    {
        $a = self::device('a');
        $keep = $a->inc('dossier:1', 'visits', 2);
        $refused = $a->inc('dossier:1', 'visits', 40);
        $a->assign('dossier:2', 'status', 'approved');
        $a->discard([$refused->opId]);
        self::assertSame(2, self::field($a, 'dossier:1', 'visits'));
        self::assertTrue($a->replica()->has($keep->opId));
        self::assertFalse($a->replica()->has($refused->opId));
        self::assertReads('{"value":"approved"}', self::field($a, 'dossier:2', 'status'));
        self::assertSame('a:4', $a->inc('dossier:1', 'visits', 1)->opId); // op ids are never reused
    }

    public function testNeverReusesAnOpIdAfterReceivingItsOwnOldOps(): void
    {
        $before = self::device('a');
        $old = [$before->inc('dossier:1', 'visits', 1), $before->inc('dossier:1', 'visits', 2)];
        $reinstalled = self::device('a');
        foreach ($old as $op) {
            $reinstalled->receive($op);
        }
        self::assertSame('a:3', $reinstalled->inc('dossier:1', 'visits', 4)->opId);
        self::assertSame(7, self::field($reinstalled, 'dossier:1', 'visits'));
    }

    public function testAdvanceSeqTakesASequenceNumberOrOneOfItsOwnOpIds(): void
    {
        $a = self::device('a');
        $a->advanceSeq(10);
        $a->advanceSeq('b:99'); // another device's id: ignored
        $a->advanceSeq(3); // never goes back
        self::assertSame('a:11', $a->inc('dossier:1', 'visits', 1)->opId);
        $a->advanceSeq('a:20');
        self::assertSame('a:21', $a->inc('dossier:1', 'visits', 1)->opId);
    }

    public function testSetsReAddingAPresentElementReplacesTheTagsItsWriterSaw(): void
    {
        $a = self::device('a');
        $b = self::device('b');
        for ($i = 0; $i < 50; $i++) {
            $a->add('dossier:1', 'documents', 'cni.pdf');
        }
        self::assertCount(1, $a->replica()->observedDeps('dossier:1', 'documents', 'cni.pdf'));
        foreach ($a->replica()->ops() as $op) {
            $b->receive($op);
        }
        $remove = $b->remove('dossier:1', 'documents', 'cni.pdf');
        $readd = $a->add('dossier:1', 'documents', 'cni.pdf');
        $a->receive($remove);
        $b->receive($readd);
        self::assertSame(['cni.pdf'], self::field($a, 'dossier:1', 'documents'));
        self::assertSame(['cni.pdf'], self::field($b, 'dossier:1', 'documents'));
    }

    public function testForgetDropsARecordExceptTheKeptLocalOps(): void
    {
        $a = self::device('a');
        $mine = $a->inc('dossier:1', 'visits', 1);
        $a->inc('dossier:1', 'visits', 2);
        $a->inc('dossier:2', 'visits', 3);
        $a->forget('dossier:1', [$mine->opId]);
        self::assertSame(1, self::field($a, 'dossier:1', 'visits'));
        self::assertSame(3, self::field($a, 'dossier:2', 'visits'));
        $a->forget('dossier:2');
        self::assertNull($a->replica()->read('dossier:2'));
    }

    public function testLoadSnapshotReplacesARecordAndReappliesKeptOps(): void
    {
        $a = self::device('a');
        $a->inc('dossier:1', 'visits', 5);
        $snap = $a->replica()->snapshotRecord('dossier:1');
        $local = $a->inc('dossier:1', 'visits', 1);
        $a->replica()->loadSnapshot($snap, [$local->opId]);
        self::assertSame(6, self::field($a, 'dossier:1', 'visits'));
        self::assertSame(1, $a->replica()->size());
        self::assertSame([$snap], $a->replica()->bases());
        // without() keeps the base snapshot.
        $a->discard([$local->opId]);
        self::assertSame(5, self::field($a, 'dossier:1', 'visits'));
    }
}
