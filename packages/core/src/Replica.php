<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * An op log and the state projected from it. Pure: no I/O, no clock. Two replicas holding the same
 * set of ops always read the same state, whatever order the ops arrived in. Port of `replica.ts`.
 */
final class Replica
{
    /** @var array<string, Op> */
    private array $ops = [];

    /** @var array<string, array<array-key, FieldState>> record → field → state (field keys may be ints in PHP) */
    private array $records = [];

    /** @var array<string, RecordSnapshot> snapshots this replica's state was started from, by record */
    private array $bases = [];

    public function __construct(public readonly Schema $schema) {}

    public function has(string $opId): bool
    {
        return isset($this->ops[$opId]);
    }

    public function size(): int
    {
        return \count($this->ops);
    }

    /**
     * All ops, in a deterministic order.
     *
     * @return list<Op>
     */
    public function ops(): array
    {
        $ops = array_values($this->ops);
        usort($ops, static fn(Op $a, Op $b): int => Op::compareIds($a->opId, $b->opId));

        return $ops;
    }

    /** Throws (without changing anything) if the op does not fit the schema. */
    public function validate(Op $op): void
    {
        $strategy = $this->schema->strategyFor($op->record, $op->field);
        if (!\in_array($op->kind(), $strategy->kinds(), true)) {
            throw new AccordException("op kind \"{$op->kind()}\" does not apply to {$op->field}, a {$strategy->value} field");
        }
        if (Op::parseId($op->opId)['device'] !== $op->hlc->node) {
            throw new AccordException("op {$op->opId} carries a clock from \"{$op->hlc->node}\"");
        }
    }

    public function apply(Op $op): ApplyResult
    {
        if (isset($this->ops[$op->opId])) {
            return ApplyResult::Duplicate;
        }
        $this->validate($op);
        $this->state($op->record, $op->field)->apply($op);
        $this->ops[$op->opId] = $op;

        return ApplyResult::Applied;
    }

    /** The record's fields, or null if no op has touched it. Fields never written are left out. */
    public function read(string $record): ?JsonObject
    {
        $states = $this->records[$record] ?? null;
        if ($states === null) {
            return null;
        }
        $out = new JsonObject();
        foreach ($this->schema->fieldsOf($record) as $field => $strategy) {
            $v = ($states[$field] ?? FieldStates::empty($strategy))->read();
            if ($v !== Absent::Value) {
                $out->set($field, $v);
            }
        }

        return $out;
    }

    /** @return list<string> record ids by UTF-16 code unit */
    public function records(): array
    {
        $ids = array_map(strval(...), array_keys($this->records));
        usort($ids, Utf16::compare(...));

        return $ids;
    }

    /**
     * Every `conflict()` field currently holding more than one value.
     *
     * @return list<array{record: string, field: string}>
     */
    public function conflicts(): array
    {
        $out = [];
        foreach ($this->records() as $record) {
            $fields = array_map(strval(...), array_keys($this->records[$record]));
            usort($fields, Utf16::compare(...));
            foreach ($fields as $field) {
                $s = $this->records[$record][$field];
                if ($s instanceof ConflictState && $s->isConflicted()) {
                    $out[] = ['record' => $record, 'field' => $field];
                }
            }
        }

        return $out;
    }

    /**
     * Op ids a new write to this field must cite (see {@see AssignOp} and {@see RemoveOp}).
     *
     * @return list<string>
     */
    public function observedDeps(string $record, string $field, string|int|float|null $element = null): array
    {
        $this->schema->strategyFor($record, $field);
        $state = $this->records[$record][$field] ?? null;

        return $state === null ? [] : $state->observedDeps($element);
    }

    /** The whole state as canonical JSON: equal strings mean converged replicas. */
    public function snapshot(): string
    {
        $all = new JsonObject();
        foreach ($this->records() as $r) {
            $all->set($r, $this->read($r));
        }

        return Json::canonical($all);
    }

    /** The record's current state, with its history folded away. */
    public function snapshotRecord(string $record): RecordSnapshot
    {
        $fields = new JsonObject();
        foreach ($this->records[$record] ?? [] as $field => $state) {
            $fields->set((string) $field, $state->snapshot());
        }

        return new RecordSnapshot($record, $fields);
    }

    /**
     * Replaces a record's state with a snapshot and forgets that record's ops, except `$keep` (local
     * ops not yet on the server), which are applied again on top.
     *
     * @param list<string> $keep
     */
    public function loadSnapshot(RecordSnapshot $snap, array $keep = []): void
    {
        $fields = [];
        foreach ($snap->fields as $field => $fs) {
            $this->schema->strategyFor($snap->record, $field);
            $fields[$field] = FieldStates::fromSnapshot($fs);
        }
        $keep = array_flip($keep);
        $reapply = [];
        foreach ($this->ops as $id => $op) {
            if ($op->record === $snap->record) {
                if (isset($keep[$id])) {
                    $reapply[] = $op;
                }
                unset($this->ops[$id]);
            }
        }
        $this->records[$snap->record] = $fields;
        $this->bases[$snap->record] = $snap;
        foreach ($reapply as $op) {
            $this->apply($op);
        }
    }

    /**
     * A copy without the given ops (same snapshots, every other op): used to roll back.
     *
     * @param list<string> $drop
     */
    public function without(array $drop): self
    {
        $drop = array_flip($drop);
        $next = new self($this->schema);
        foreach ($this->bases as $snap) {
            $next->loadSnapshot($snap);
        }
        foreach ($this->ops() as $op) {
            if (!isset($drop[$op->opId])) {
                $next->apply($op);
            }
        }

        return $next;
    }

    /**
     * Forgets a record entirely (it left this device's scope), except ops in `$keep`.
     *
     * @param list<string> $keep
     */
    public function forget(string $record, array $keep = []): self
    {
        $keep = array_flip($keep);
        $next = new self($this->schema);
        foreach ($this->bases as $r => $snap) {
            if ($r !== $record) {
                $next->loadSnapshot($snap);
            }
        }
        foreach ($this->ops() as $op) {
            if ($op->record !== $record || isset($keep[$op->opId])) {
                $next->apply($op);
            }
        }

        return $next;
    }

    /**
     * Snapshots this replica was started from (to persist them alongside the ops).
     *
     * @return list<RecordSnapshot>
     */
    public function bases(): array
    {
        return array_values($this->bases);
    }

    private function state(string $record, string $field): FieldState
    {
        return $this->records[$record][$field] ??= FieldStates::empty($this->schema->strategyFor($record, $field));
    }
}
