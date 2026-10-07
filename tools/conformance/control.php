<?php

declare(strict_types=1);

/*
 * The conformance control API under PHP's built-in server:
 *
 *   ACCORD_DATABASE_URL=postgres://… php -S 127.0.0.1:8802 tools/conformance/control.php
 *
 * run.sh serves it with control-server.php instead (a plain CLI process): on the GitHub runner,
 * `php -S` crashed in the engine while serving these routes. Test-only: never expose it.
 */

use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\ServerDefinition;

/** @var array{0: ServerDefinition, 1: Closure(): PDO, 2: RateLimiter} $boot */
$boot = require __DIR__ . '/bootstrap.php';
[$definition, $connect, $rateLimiter] = $boot;
require_once __DIR__ . '/control-lib.php';

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
