<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * One device's replica plus the means to write to it: every local write becomes an op, applied
 * locally first and returned so the caller can queue it for sync. Port of `writer.ts`.
 */
final class LocalWriter
{
    public const int DEFAULT_MAX_SKEW_MS = 24 * 60 * 60 * 1000;

    private Replica $replica;

    private Hlc $hlc;

    private int $seq;

    /**
     * @param \Closure(): int $now physical time in ms; the core never reads the clock itself
     * @param int $maxSkewMs remote clocks further ahead than this are refused
     * @param ?Hlc $resumeHlc with `$resumeSeq`: a device's last clock and op sequence number
     */
    public function __construct(
        Schema $schema,
        public readonly string $deviceId,
        private readonly \Closure $now,
        private readonly int $maxSkewMs = self::DEFAULT_MAX_SKEW_MS,
        ?Hlc $resumeHlc = null,
        int $resumeSeq = 0,
    ) {
        Hlc::assertNode($deviceId);
        $this->replica = new Replica($schema);
        $this->hlc = $resumeHlc ?? Hlc::initial($deviceId);
        $this->seq = $resumeSeq;
    }

    public function replica(): Replica
    {
        return $this->replica;
    }

    public function clock(): Hlc
    {
        return $this->hlc;
    }

    public function seq(): int
    {
        return $this->seq;
    }

    /** `$value` is a JSON value in the core's model: use a {@see JsonObject} for objects. */
    public function assign(string $record, string $field, mixed $value): AssignOp
    {
        self::assertJson($value);
        $deps = $this->replica->observedDeps($record, $field);
        [$opId, $hlc] = $this->base($record, $field);

        return $this->write(new AssignOp($opId, $record, $field, $hlc, $value, $deps));
    }

    public function inc(string $record, string $field, int $by): IncOp
    {
        if (abs($by) > Json::MAX_SAFE_INTEGER) {
            throw new AccordException("counter increment must be a safe integer, got $by");
        }
        [$opId, $hlc] = $this->base($record, $field);

        return $this->write(new IncOp($opId, $record, $field, $hlc, $by));
    }

    public function add(string $record, string $field, mixed $element): AddOp
    {
        $element = self::element($element);
        $deps = $this->replica->observedDeps($record, $field, $element);
        [$opId, $hlc] = $this->base($record, $field);

        return $this->write(new AddOp($opId, $record, $field, $hlc, $element, $deps));
    }

    public function remove(string $record, string $field, mixed $element): RemoveOp
    {
        $element = self::element($element);
        $deps = $this->replica->observedDeps($record, $field, $element);
        [$opId, $hlc] = $this->base($record, $field);

        return $this->write(new RemoveOp($opId, $record, $field, $hlc, $element, $deps));
    }

    /** Applies an op from elsewhere. Refuses it, leaving state untouched, if its clock is absurd. */
    public function receive(Op $op): ApplyResult
    {
        if ($this->replica->has($op->opId)) {
            $this->advanceSeq($op->opId);

            return ApplyResult::Duplicate;
        }
        $this->replica->validate($op);
        $next = $this->hlc->receive($op->hlc, ($this->now)(), $this->maxSkewMs);
        $result = $this->replica->apply($op);
        $this->hlc = $next;
        $this->advanceSeq($op->opId);

        return $result;
    }

    /**
     * Makes sure future op ids come after `$seenOrSeq`: an op id of this device (as received from the
     * server after a reinstall) or a sequence number the server reports. Op ids are never reused.
     */
    public function advanceSeq(string|int $seenOrSeq): void
    {
        if (\is_int($seenOrSeq)) {
            $seq = $seenOrSeq;
        } else {
            $id = Op::parseId($seenOrSeq);
            if ($id['device'] !== $this->deviceId) {
                return;
            }
            $seq = $id['seq'];
        }
        if ($seq > $this->seq) {
            $this->seq = $seq;
        }
    }

    /**
     * Rolls back ops the server refused: the replica is rebuilt from its log without them, so this
     * device converges with everyone else. The clock and sequence number are not rewound; op ids
     * are never reused.
     *
     * @param iterable<string> $opIds
     */
    public function discard(iterable $opIds): void
    {
        $drop = [];
        foreach ($opIds as $id) {
            $drop[] = $id;
        }
        $this->replica = $this->replica->without($drop);
    }

    /**
     * The record left this device's scope: forget it, except the local ops in `$keep`.
     *
     * @param list<string> $keep
     */
    public function forget(string $record, array $keep = []): void
    {
        $this->replica = $this->replica->forget($record, $keep);
    }

    /** @return array{string, Hlc} */
    private function base(string $record, string $field): array
    {
        // Validate before consuming a clock tick or sequence number.
        $this->replica->observedDeps($record, $field);
        $this->hlc = $this->hlc->tick(($this->now)());
        $this->seq += 1;

        return ["{$this->deviceId}:{$this->seq}", $this->hlc];
    }

    /**
     * @template T of Op
     * @param T $op
     * @return T
     */
    private function write(Op $op): Op
    {
        $this->replica->apply($op);

        return $op;
    }

    private static function element(mixed $e): string|int|float
    {
        if (!Elements::isElement($e)) {
            throw new AccordException('set elements must be a string or number');
        }

        return $e;
    }

    /** Values must survive a JSON round trip: PHP arrays that are not lists are refused (use JsonObject). */
    private static function assertJson(mixed $v): void
    {
        if ($v === null || \is_bool($v) || \is_int($v) || \is_float($v) || \is_string($v)) {
            return;
        }
        if (\is_array($v) && array_is_list($v)) {
            array_walk($v, static fn(mixed $x) => self::assertJson($x));

            return;
        }
        if ($v instanceof JsonObject) {
            foreach ($v as $x) {
                self::assertJson($x);
            }

            return;
        }
        throw new AccordException('value must be JSON (use JsonObject for objects, null for "no value")');
    }
}
