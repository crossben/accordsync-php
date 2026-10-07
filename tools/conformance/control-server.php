<?php

declare(strict_types=1);

/*
 * The conformance control API as a plain, long-running PHP CLI process (no `php -S`):
 *
 *   ACCORD_DATABASE_URL=postgres://… php tools/conformance/control-server.php 8802
 *
 * On the GitHub runner, `php -S` crashed in the engine (a segfault in
 * zend_std_get_static_property_with_info) while serving these routes, though never locally. This
 * loop serves the same routes (control-lib.php) one connection at a time, which is all the control
 * API needs: its requests come one after another, and a held record's transaction lives in a
 * separate helper process (RecordHold). Test-only: never expose it.
 */

use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\ServerDefinition;

/** @var array{0: ServerDefinition, 1: Closure(): PDO, 2: RateLimiter} $boot */
$boot = require __DIR__ . '/bootstrap.php';
[$definition, $connect, $rateLimiter] = $boot;
require_once __DIR__ . '/control-lib.php';

$port = (int) ($argv[1] ?? getenv('ACCORD_CONTROL_PORT') ?: 8802);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "control-server: cannot listen on 127.0.0.1:$port: $error\n");
    exit(1);
}
fwrite(STDERR, "control-server: listening on 127.0.0.1:$port\n");

/**
 * Reads one HTTP/1.1 request: [method, target], or null if the client went away.
 *
 * @param resource $conn
 *
 * @return array{0: string, 1: string}|null
 */
function accord_control_read($conn): ?array
{
    stream_set_timeout($conn, 30);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $head .= $chunk;
    }
    [$headers, $body] = explode("\r\n\r\n", $head, 2);
    $lines = explode("\r\n", $headers);
    $parts = explode(' ', $lines[0]);
    if (\count($parts) < 2) {
        return null;
    }
    // Drain the body (the control routes take their input from the query string).
    $length = 0;
    foreach (\array_slice($lines, 1) as $line) {
        if (stripos($line, 'content-length:') === 0) {
            $length = (int) trim(substr($line, 15));
        }
    }
    while (\strlen($body) < $length) {
        $chunk = fread($conn, max(1, $length - \strlen($body)));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }

    return [$parts[0], $parts[1]];
}

const ACCORD_CONTROL_REASONS = [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 409 => 'Conflict', 500 => 'Internal Server Error'];

while (\is_resource($server)) {
    $conn = @stream_socket_accept($server, -1);
    if ($conn === false) {
        continue;
    }
    $request = accord_control_read($conn);
    if ($request === null) {
        fclose($conn);
        continue;
    }
    [$method, $target] = $request;
    $path = (string) parse_url($target, PHP_URL_PATH);
    $query = (string) parse_url($target, PHP_URL_QUERY);
    try {
        $status = 200;
        $body = json_encode(accord_control($method, $path, $query, $definition, $connect, $rateLimiter), JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        $status = $e instanceof ControlError ? (int) $e->getCode() : 500;
        if ($status === 500) {
            fwrite(STDERR, (string) $e . "\n");
        }
        $body = (string) json_encode(['error' => $e->getMessage()]);
    }
    $reason = ACCORD_CONTROL_REASONS[$status] ?? 'Error';
    fwrite($conn, "HTTP/1.1 $status $reason\r\nContent-Type: application/json\r\nContent-Length: " . \strlen($body)
        . "\r\nConnection: close\r\n\r\n" . $body);
    fclose($conn);
}
