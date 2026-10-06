<?php

declare(strict_types=1);

namespace Accord\Core\Tests;

use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Core\Op;
use Accord\Core\Replica;
use Accord\Core\Schema;
use Accord\Core\Strategy;
use Accord\Core\Wire;

/** Helpers shared by the core tests. */
final class Support
{
    public static function readJson(string $file): JsonObject
    {
        $v = Json::decode((string) file_get_contents($file));
        if (!$v instanceof JsonObject) {
            throw new \RuntimeException("$file is not a JSON object");
        }

        return $v;
    }

    /** `{"dossier": {"name": "lww", …}}` → Schema. */
    public static function schemaFromJson(mixed $json): Schema
    {
        if (!$json instanceof JsonObject) {
            throw new \RuntimeException('schema must be an object');
        }
        $types = [];
        foreach ($json as $type => $fields) {
            if (!$fields instanceof JsonObject) {
                throw new \RuntimeException('fields must be an object');
            }
            foreach ($fields as $field => $name) {
                $types[$type][$field] = Strategy::from((string) (\is_string($name) ? $name : ''));
            }
        }

        return Schema::define($types);
    }

    /** @return list<Op> */
    public static function decodeOps(mixed $ops): array
    {
        if (!\is_array($ops)) {
            throw new \RuntimeException('ops must be an array');
        }

        return array_values(array_map(Wire::decode(...), $ops));
    }

    /** @param iterable<Op> $ops */
    public static function replay(Schema $schema, iterable $ops): Replica
    {
        $r = new Replica($schema);
        foreach ($ops as $op) {
            $r->apply($op);
        }

        return $r;
    }

    /**
     * @template T
     * @param list<T> $xs
     * @return \Generator<list<T>>
     */
    public static function permutations(array $xs): \Generator
    {
        if (\count($xs) <= 1) {
            yield $xs;

            return;
        }
        foreach ($xs as $i => $x) {
            $rest = $xs;
            array_splice($rest, $i, 1);
            foreach (self::permutations($rest) as $p) {
                yield [$x, ...$p];
            }
        }
    }
}
