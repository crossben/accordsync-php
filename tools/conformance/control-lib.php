<?php

declare(strict_types=1);

/*
 * The conformance control API (PROFILE.md "Control API"): the routes, shared by the two ways of
 * serving it (control.php under `php -S`, control-server.php as a plain CLI process). Test-only:
 * never expose it.
 */

use Accord\Examples\RecordHold;
use Accord\Server\AccordServer;
use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\ServerDefinition;
use Firebase\JWT\JWT;

// The held transaction of /hold-record lives in a helper process (see RecordHold).
require_once __DIR__ . '/../../examples/shared/RecordHold.php';

final class ControlError extends RuntimeException {}

/**
 * Query parameters with repeats kept (`zone=a&zone=b`), which PHP's $_GET drops.
 *
 * @return array<string, list<string>>
 */
function accord_control_query(string $query): array
{
    $out = [];
    foreach (explode('&', $query) as $pair) {
        if ($pair === '') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $out[urldecode($k)][] = urldecode($v);
    }

    return $out;
}

/** @return array<string, mixed>|object */
function accord_control(string $method, string $path, string $query, ServerDefinition $definition, Closure $connect, RateLimiter $rateLimiter): array|object
{
    $q = accord_control_query($query);
    if ($method === 'GET' && $path === '/token') {
        $sub = $q['sub'][0] ?? '';
        if ($sub === '') {
            throw new ControlError('sub is required', 400);
        }
        $now = time();
        $claims = ['sub' => $sub, 'iss' => ACCORD_CONFORMANCE_ISSUER, 'iat' => $now];
        if (isset($q['zone'])) {
            $claims['zones'] = $q['zone'];
        }
        if (isset($q['readonly_zone'])) {
            $claims['readonly_zones'] = $q['readonly_zone'];
        }
        $claims['exp'] = $now + (int) ($q['exp_in'][0] ?? 3600);

        return ['token' => JWT::encode($claims, ACCORD_CONFORMANCE_SECRET, 'HS256')];
    }
    /** @var PDO $pdo */
    $pdo = $connect();
    $hold = new RecordHold((string) getenv('ACCORD_DATABASE_URL'));
    if ($method === 'POST' && $path === '/reset') {
        $hold->release();
        $pdo->exec('truncate feed, records, devices, compacted_ops restart identity');
        $rateLimiter->clear();

        return new stdClass();
    }
    if ($method === 'POST' && $path === '/compact') {
        return AccordServer::compact($definition, $pdo)->jsonSerialize();
    }
    if ($method === 'POST' && $path === '/age-device') {
        $device = $q['device'][0] ?? '';
        $days = $q['days'][0] ?? '';
        if ($device === '' || !is_numeric($days)) {
            throw new ControlError('device and days are required', 400);
        }
        $st = $pdo->prepare('update devices set last_seen = now() - make_interval(days => ?::int) where device_id = ?');
        $st->execute([(int) $days, $device]);
        if ($st->rowCount() === 0) {
            throw new ControlError('unknown device', 404);
        }

        return new stdClass();
    }
    if ($method === 'POST' && $path === '/hold-record') {
        $record = $q['record'][0] ?? '';
        if ($record === '') {
            throw new ControlError('record is required', 400);
        }
        [$status, $body] = $hold->hold($record);
        if ($status !== 200) {
            throw new ControlError(\is_array($body) && \is_string($body['error'] ?? null) ? $body['error'] : 'hold failed', $status);
        }

        return $body;
    }
    if ($method === 'GET' && $path === '/held') {
        return ['waiting' => $hold->waiting($pdo)];
    }
    if ($method === 'POST' && $path === '/release') {
        $hold->release();

        return new stdClass();
    }
    throw new ControlError('not found', 404);
}
