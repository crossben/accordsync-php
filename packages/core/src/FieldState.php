<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * Per-field state. Each strategy's `apply` is commutative and associative over distinct ops: any
 * delivery order of the same ops gives the same state. The replica applies each op at most once,
 * which makes the merge idempotent. Port of `strategies.ts`.
 */
interface FieldState
{
    public function strategy(): Strategy;

    /** Applies `$op` in place. The op must already be validated against the schema. */
    public function apply(Op $op): void;

    /** The field as the app sees it, or {@see Absent::Value} when never written. */
    public function read(): mixed;

    /**
     * Op ids a writer must cite in `deps`: live conflict values, or the tags of a set element.
     *
     * @return list<string>
     */
    public function observedDeps(string|int|float|null $element = null): array;

    /**
     * The live state as JSON; tombstones are dropped (ADR-0008). Same shape as the TypeScript
     * `FieldSnapshot`, so snapshots from the server load here unchanged.
     */
    public function snapshot(): JsonObject;
}
