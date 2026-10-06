<?php

declare(strict_types=1);

namespace Accord\Core;

/** A record's state with its history folded away (log compaction, ADR-0008). */
final readonly class RecordSnapshot
{
    /** @param JsonObject $fields field → the field's snapshot JSON (the TypeScript `FieldSnapshot`) */
    public function __construct(public string $record, public JsonObject $fields) {}

    public static function fromJson(mixed $json): self
    {
        if (!$json instanceof JsonObject || !\is_string($json->get('record')) || !$json->get('fields') instanceof JsonObject) {
            throw new AccordException('record snapshot must be {record, fields}');
        }

        return new self($json->get('record'), $json->get('fields'));
    }

    public function toJson(): JsonObject
    {
        return new JsonObject(['record' => $this->record, 'fields' => $this->fields]);
    }
}
