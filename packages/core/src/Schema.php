<?php

declare(strict_types=1);

namespace Accord\Core;

/** Record type → field → strategy. Port of `schema.ts`. */
final readonly class Schema
{
    private const string TYPE = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/D';

    /** @param array<string, array<string, Strategy>> $types field names always as strings */
    private function __construct(private array $types) {}

    /**
     * Checks and returns a schema. Field names like "10" are fine: PHP makes them int keys, and they
     * are read back as strings.
     *
     * @param array<array-key, array<array-key, Strategy>> $types
     */
    public static function define(array $types): self
    {
        $out = [];
        foreach ($types as $type => $fields) {
            $type = (string) $type;
            if (preg_match(self::TYPE, $type) !== 1) {
                throw new AccordException("invalid record type \"$type\"");
            }
            $out[$type] = [];
            foreach ($fields as $field => $strategy) {
                $out[$type][(string) $field] = $strategy;
            }
        }

        return new self($out);
    }

    public static function lww(): Strategy
    {
        return Strategy::Lww;
    }

    public static function counter(): Strategy
    {
        return Strategy::Counter;
    }

    public static function set(): Strategy
    {
        return Strategy::Set;
    }

    public static function conflict(): Strategy
    {
        return Strategy::Conflict;
    }

    /** The strategy for `record.field`, or an error naming what is wrong. */
    public function strategyFor(string $record, string $field): Strategy
    {
        $type = Op::recordType($record);
        $fields = $this->types[$type] ?? throw new AccordException("unknown record type \"$type\"");

        return $fields[$field] ?? throw new AccordException("unknown field \"$type.$field\"");
    }

    /** @return iterable<string, Strategy> */
    public function fieldsOf(string $record): iterable
    {
        $type = Op::recordType($record);
        if (!isset($this->types[$type])) {
            throw new AccordException("unknown record type \"$type\"");
        }
        foreach ($this->types[$type] as $field => $strategy) {
            yield (string) $field => $strategy;
        }
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_map(strval(...), array_keys($this->types));
    }
}
