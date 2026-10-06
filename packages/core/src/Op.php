<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * One change to one field of one record. Port of `op.ts`.
 *
 * Values are JSON in the core's value model (see {@see JsonObject}); set elements are strings or
 * numbers. The op id, `deviceId:sequence`, is unique per op, so applying an op twice is a no-op.
 */
abstract readonly class Op
{
    private const string OP_ID = '/^([A-Za-z0-9_-]{1,64}):([1-9][0-9]{0,15})$/D';

    private const string RECORD_ID = '/^([A-Za-z][A-Za-z0-9_]{0,63}):(.+)$/Ds';

    /** @param string $record `type:id`, for example `dossier:91` */
    public function __construct(
        public string $opId,
        public string $record,
        public string $field,
        public Hlc $hlc,
    ) {}

    /** `assign`, `inc`, `add` or `remove`. */
    abstract public function kind(): string;

    /**
     * The device and sequence number of an op id.
     *
     * @return array{device: string, seq: int}
     */
    public static function parseId(string $opId): array
    {
        if (preg_match(self::OP_ID, $opId, $m) !== 1) {
            throw new AccordException("malformed op id \"$opId\" (expected device:sequence)");
        }

        return ['device' => $m[1], 'seq' => (int) $m[2]];
    }

    /** The type of a record id (`dossier` for `dossier:91`). */
    public static function recordType(string $record): string
    {
        // The id part is 1 to 256 UTF-16 code units, as JavaScript's `.{1,256}` counts them.
        if (preg_match(self::RECORD_ID, $record, $m) !== 1 || Utf16::length($m[2]) > 256) {
            throw new AccordException("malformed record id \"$record\" (expected type:id)");
        }

        return $m[1];
    }

    /** Sort order for op ids: UTF-16 code units, exactly like JavaScript's `<` on strings. */
    public static function compareIds(string $a, string $b): int
    {
        return Utf16::compare($a, $b);
    }
}
