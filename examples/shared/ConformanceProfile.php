<?php

declare(strict_types=1);

namespace Accord\Examples;

use Accord\Core\Schema;
use Accord\Server\Access;
use Accord\Server\AccordServer;
use Accord\Server\Auth;
use Accord\Server\Compaction;
use Accord\Server\CompactionResult;
use Accord\Server\Limits;
use Accord\Server\RateLimit;
use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\RateLimits;
use Accord\Server\ScopedRecord;
use Accord\Server\ServerDefinition;
use Firebase\JWT\JWT;

/**
 * Shared by both example apps (test-only): the conformance profile (contract/conformance/profile.json)
 * as a server definition, and the control API's actions (PROFILE.md "Control API"). The apps only
 * route to it, so a difference between them can only come from the bridges.
 */
final class ConformanceProfile
{
    /** @var array<string, mixed> */
    private array $profile;

    public function __construct(?string $path = null)
    {
        $env = getenv('ACCORD_PROFILE');
        $path ??= \is_string($env) && $env !== '' ? $env : __DIR__ . '/../../contract/conformance/profile.json';
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("cannot read the conformance profile at $path");
        }
        /** @var array<string, mixed> $profile */
        $profile = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $this->profile = $profile;
    }

    public function definition(): ServerDefinition
    {
        $p = $this->profile;
        $strategies = ['lww' => Schema::lww(...), 'counter' => Schema::counter(...), 'set' => Schema::set(...), 'conflict' => Schema::conflict(...)];
        $types = [];
        $scopes = [];
        foreach (self::map($p['schema']) as $type => $fields) {
            $types[$type] = array_map(static fn(mixed $s) => $strategies[(string) $s](), self::map($fields));
            $templates = array_map(static fn(mixed $rule): string => (string) self::map($rule)['key'], self::map($p['scopes'])[$type] ?? []);
            $scopes[$type] = static function (ScopedRecord $r) use ($templates): array {
                $keys = [];
                foreach ($templates as $t) {
                    [$prefix, $field] = self::template($t);
                    $keys = [...$keys, ...ScopedRecord::key($prefix, $r->fields[$field] ?? null)];
                }

                return $keys;
            };
        }
        $access = self::map($p['access']);
        $read = array_map(strval(...), self::map($access['read']));
        $write = array_map(strval(...), self::map($access['write']));
        $auth = self::map($p['auth']);
        $limits = self::map($p['limits']);
        $rate = self::map($p['rateLimit']);
        $compaction = self::map($p['compaction']);
        $bucket = static fn(mixed $b): RateLimit => new RateLimit((float) self::map($b)['perMinute'], (float) self::map($b)['burst']);

        return AccordServer::define(
            schema: Schema::define($types),
            scopes: $scopes,
            access: static fn(array $claims): Access => new Access(read: self::grant($read, $claims), write: self::grant($write, $claims)),
            auth: Auth::hs256(self::str($auth['hs256Secret']), issuer: self::str($auth['issuer'])),
            rateLimit: new RateLimits(perDevice: $bucket($rate['perDevice']), perUser: $bucket($rate['perUser'])),
            compaction: new Compaction(deviceTtlDays: (float) $compaction['deviceTtlDays'], intervalMs: (int) $compaction['intervalMs'], minOps: (int) $compaction['minOps']),
            limits: new Limits(
                maxBodyBytes: (int) $limits['maxBodyBytes'],
                maxScopeDelta: (int) $limits['maxScopeDelta'],
                maxPushOps: (int) $limits['maxPushOps'],
                maxPullLimit: (int) $limits['maxPullLimit'],
                maxSkewMs: (int) $limits['maxSkewMs'],
            ),
        );
    }

    /**
     * Runs one control API route. Returns [status, body].
     *
     * @param array<string, list<string>> $query repeated parameters kept
     * @param \Closure(): \PDO $pdo
     * @param string $databaseUrl the same database, for the connection of `/hold-record` (RecordHold)
     *
     * @return array{0: int, 1: array<string, mixed>|\stdClass}
     */
    public function control(string $method, string $path, array $query, \Closure $pdo, RateLimiter $rateLimiter, \Closure $compact, string $databaseUrl): array
    {
        $hold = new RecordHold($databaseUrl);
        $path = '/' . ltrim($path, '/');
        if ($method === 'GET' && $path === '/token') {
            $sub = $query['sub'][0] ?? '';
            if ($sub === '') {
                return [400, ['error' => 'sub is required']];
            }
            $auth = self::map($this->profile['auth']);
            $now = time();
            $claims = ['sub' => $sub, 'iss' => self::str($auth['issuer']), 'iat' => $now];
            if (isset($query['zone'])) {
                $claims['zones'] = $query['zone'];
            }
            if (isset($query['readonly_zone'])) {
                $claims['readonly_zones'] = $query['readonly_zone'];
            }
            $claims['exp'] = $now + (int) ($query['exp_in'][0] ?? 3600);

            return [200, ['token' => JWT::encode($claims, self::str($auth['hs256Secret']), 'HS256')]];
        }
        if ($method === 'POST' && $path === '/reset') {
            $hold->release();
            $pdo()->exec('truncate feed, records, devices, compacted_ops restart identity');
            $rateLimiter->clear();

            return [200, new \stdClass()];
        }
        if ($method === 'POST' && $path === '/compact') {
            $result = $compact();
            \assert($result instanceof CompactionResult);

            return [200, $result->jsonSerialize()];
        }
        if ($method === 'POST' && $path === '/age-device') {
            $device = $query['device'][0] ?? '';
            $days = $query['days'][0] ?? '';
            if ($device === '' || !is_numeric($days)) {
                return [400, ['error' => 'device and days are required']];
            }
            $st = $pdo()->prepare('update devices set last_seen = now() - make_interval(days => ?::int) where device_id = ?');
            $st->execute([(int) $days, $device]);

            return $st->rowCount() === 0 ? [404, ['error' => 'unknown device']] : [200, new \stdClass()];
        }

        if ($method === 'POST' && $path === '/hold-record') {
            $record = $query['record'][0] ?? '';

            return $record === '' ? [400, ['error' => 'record is required']] : $hold->hold($record);
        }
        if ($method === 'GET' && $path === '/held') {
            return [200, ['waiting' => $hold->waiting($pdo())]];
        }
        if ($method === 'POST' && $path === '/release') {
            $hold->release();

            return [200, new \stdClass()];
        }

        return [404, ['error' => 'not found']];
    }

    /**
     * Query parameters with repeats kept (`zone=a&zone=b`), which PHP's parse_str drops.
     *
     * @return array<string, list<string>>
     */
    public static function query(string $query): array
    {
        $out = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair !== '') {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $out[urldecode($k)][] = urldecode($v);
            }
        }

        return $out;
    }

    /**
     * Expands profile access rules: "agent:{sub}" and "zone:{z} for z in zones".
     *
     * @param list<string> $rules
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private static function grant(array $rules, array $claims): array
    {
        $keys = [];
        foreach ($rules as $rule) {
            if (preg_match('/^(\w+):\{(\w+)\}(?: for \2 in (\w+))?$/', $rule, $m) !== 1) {
                throw new \UnexpectedValueException("unsupported access rule \"$rule\"");
            }
            $values = isset($m[3]) ? (\is_array($claims[$m[3]] ?? null) ? $claims[$m[3]] : []) : [$claims[$m[2]] ?? null];
            foreach ($values as $v) {
                $keys = [...$keys, ...ScopedRecord::key($m[1], $v)];
            }
        }

        return $keys;
    }

    /** @return array{0: string, 1: string} "agent:{agent}" → [agent, agent] */
    private static function template(string $t): array
    {
        if (preg_match('/^(\w+):\{(\w+)\}$/', $t, $m) !== 1) {
            throw new \UnexpectedValueException("unsupported scope key \"$t\"");
        }

        return [$m[1], $m[2]];
    }

    /** @return array<array-key, mixed> */
    private static function map(mixed $v): array
    {
        return \is_array($v) ? $v : throw new \UnexpectedValueException('profile.json: expected an object or array');
    }

    private static function str(mixed $v): string
    {
        return \is_string($v) ? $v : throw new \UnexpectedValueException('profile.json: expected a string');
    }
}
