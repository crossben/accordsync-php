<?php

declare(strict_types=1);

/*
 * The conformance control API (PROFILE.md "Control API"), for PHP's built-in server on a second,
 * test-only port. Never expose it.
 *
 *   ACCORD_DATABASE_URL=postgres://… php -S 127.0.0.1:8802 tools/conformance/control.php
 */

use Accord\Server\AccordServer;
use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\ServerDefinition;
use Firebase\JWT\JWT;

/** @var array{0: ServerDefinition, 1: Closure(): PDO, 2: RateLimiter} $boot */
$boot = require __DIR__ . '/bootstrap.php';
[$definition, $connect, $rateLimiter] = $boot;

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
    if ($method === 'POST' && $path === '/reset') {
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
    throw new ControlError('not found', 404);
}

header('Content-Type: application/json');
try {
    $uri = \is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $query = (string) parse_url($uri, PHP_URL_QUERY);
    $method = \is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    echo json_encode(accord_control($method, $path, $query, $definition, $connect, $rateLimiter), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $status = $e instanceof ControlError ? (int) $e->getCode() : 500;
    if ($status === 500) {
        error_log((string) $e);
    }
    http_response_code($status);
    echo json_encode(['error' => $e->getMessage()]);
}
