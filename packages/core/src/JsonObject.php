<?php

declare(strict_types=1);

namespace Accord\Core;

/**
 * A JSON object. PHP arrays cannot carry one: `[]` is also the empty array, and the key "10"
 * silently becomes the integer 10. Keys here are always strings; JSON arrays are PHP lists.
 *
 * The JSON value model of the core: null, bool, int, float, string (WTF-8), list, JsonObject.
 *
 * @implements \IteratorAggregate<string, mixed>
 */
final class JsonObject implements \Countable, \IteratorAggregate
{
    /** @var array<int|string, mixed> PHP may store "10" as 10; keys are cast back on the way out */
    private array $entries = [];

    /** @param iterable<int|string, mixed> $entries key => JSON value */
    public function __construct(iterable $entries = [])
    {
        foreach ($entries as $k => $v) {
            $this->entries[(string) $k] = $v;
        }
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->entries);
    }

    public function get(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->entries[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->entries[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->entries));
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /** @return \Generator<string, mixed> */
    public function getIterator(): \Generator
    {
        foreach ($this->entries as $k => $v) {
            yield (string) $k => $v;
        }
    }
}
