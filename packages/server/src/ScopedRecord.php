<?php

declare(strict_types=1);

namespace Accord\Server;

/** A record as scope functions see it: its id and its current field values (core JSON value model). */
final readonly class ScopedRecord
{
    /** @param array<string, mixed> $fields field → current value; never-written fields are absent */
    public function __construct(public string $id, public array $fields) {}

    /**
     * `["$prefix:$value"]` when `$value` is a string, otherwise no key: the usual shape of a scope.
     *
     * @return list<string>
     */
    public static function key(string $prefix, mixed $value): array
    {
        return \is_string($value) ? ["$prefix:$value"] : [];
    }
}
