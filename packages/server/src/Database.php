<?php

declare(strict_types=1);

namespace Accord\Server;

/** A PostgreSQL connection (opened on first use) and the few helpers the server's SQL needs. */
final class Database
{
    private ?\PDO $pdo;

    /** @var ?\Closure(): \PDO */
    private ?\Closure $factory;

    /** @param \PDO|\Closure(): \PDO $db */
    public function __construct(\PDO|\Closure $db)
    {
        if ($db instanceof \PDO) {
            $this->pdo = $db;
            $this->factory = null;
        } else {
            $this->pdo = null;
            $this->factory = $db;
        }
    }

    /**
     * Opens `postgres://user:password@host:port/database?sslmode=…` (the TypeScript server's
     * ACCORD_DATABASE_URL) with PDO. `$persistent` reuses the connection across requests of one
     * worker (PHP-FPM, `php -S`).
     */
    public static function connect(string $url, bool $persistent = false): \PDO
    {
        $p = parse_url($url);
        if ($p === false || !\in_array($p['scheme'] ?? '', ['postgres', 'postgresql'], true)) {
            throw new \InvalidArgumentException('the database URL must look like postgres://user:password@host:5432/database');
        }
        $dsn = 'pgsql:host=' . ($p['host'] ?? 'localhost') . ';port=' . ($p['port'] ?? 5432) . ';dbname=' . ltrim(rawurldecode($p['path'] ?? ''), '/');
        parse_str($p['query'] ?? '', $q);
        if (\is_string($q['sslmode'] ?? null)) {
            $dsn .= ';sslmode=' . $q['sslmode'];
        }

        return self::options(new \PDO(
            $dsn,
            isset($p['user']) ? rawurldecode($p['user']) : null,
            isset($p['pass']) ? rawurldecode($p['pass']) : null,
            [\PDO::ATTR_PERSISTENT => $persistent],
        ));
    }

    /** Errors as exceptions, native prepares, native types. */
    public static function options(\PDO $pdo): \PDO
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);

        return $pdo;
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $factory = $this->factory ?? throw new \LogicException('no connection');
            $this->pdo = self::options($factory());
        }

        return $this->pdo;
    }

    /** @param list<scalar|null> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);

        return $st;
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @param list<scalar|null> $params
     *
     * @return ?array<string, mixed>
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        /** @var ?array<string, mixed> */
        return $row === false ? null : $row;
    }

    /**
     * Runs `$fn` in a transaction: committed if it returns, rolled back if it throws.
     *
     * @template T
     *
     * @param \Closure(): T $fn
     *
     * @return T
     */
    public function transaction(\Closure $fn, ?string $isolation = null, bool $asyncCommit = false): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            if ($isolation !== null) {
                $pdo->exec("set transaction isolation level $isolation");
            }
            if ($asyncCommit) {
                $pdo->exec('set local synchronous_commit = off');
            }
            $result = $fn();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** A text column's value. */
    public static function str(mixed $v): string
    {
        return match (true) {
            \is_string($v) => $v,
            \is_int($v) => (string) $v,
            default => throw new \UnexpectedValueException('expected text, got ' . get_debug_type($v)),
        };
    }

    /** A bigint column's value (pdo_pgsql may return it as a string). */
    public static function int(mixed $v): int
    {
        return match (true) {
            \is_int($v) => $v,
            \is_string($v) && preg_match('/^-?\d+$/D', $v) === 1 => (int) $v,
            default => throw new \UnexpectedValueException('expected an integer, got ' . get_debug_type($v)),
        };
    }

    /**
     * A PostgreSQL `text[]` literal, for a `?::text[]` parameter.
     *
     * @param list<string> $items
     */
    public static function textArray(array $items): string
    {
        return '{' . implode(',', array_map(static fn(string $s): string => '"' . addcslashes($s, '"\\') . '"', $items)) . '}';
    }

    /**
     * A `text[]` column selected as `to_json(...)`, back to a list (null stays null).
     *
     * @return ?list<string>
     */
    public static function stringList(mixed $json): ?array
    {
        if ($json === null) {
            return null;
        }
        $list = json_decode(self::str($json), true, 4, JSON_THROW_ON_ERROR);
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \UnexpectedValueException('expected a JSON array of strings');
        }

        return array_map(self::str(...), $list);
    }
}
