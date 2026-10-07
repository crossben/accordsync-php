<?php

declare(strict_types=1);

namespace App\Accord;

use Accord\Examples\ConformanceProfile;
use Accord\Laravel\Accord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The conformance control API (test-only). Answers only when the process environment has
 * ACCORD_CONTROL_ENABLED=true: read with getenv(), not config, because the cached config is shared by
 * the sync and control servers.
 */
final class ControlController
{
    public function __invoke(Request $request, Accord $accord, string $action): JsonResponse
    {
        if (!filter_var(getenv('ACCORD_CONTROL_ENABLED'), FILTER_VALIDATE_BOOL)) {
            abort(404);
        }
        $query = $request->server->get('QUERY_STRING');
        [$status, $body] = (new ConformanceProfile())->control(
            $request->getMethod(),
            $action,
            ConformanceProfile::query(is_string($query) ? $query : ''),
            $accord->pdo(...),
            $accord->rateLimiter(),
            $accord->compact(...),
            self::databaseUrl(),
        );

        return new JsonResponse($body, $status);
    }

    /** The database URL, for the connection of /hold-record: Accord's own, else Laravel's. */
    private static function databaseUrl(): string
    {
        $url = config('accord.database.url');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        $name = config('accord.database.connection') ?? config('database.default');
        $c = config('database.connections.' . (is_string($name) ? $name : 'pgsql'));
        if (!is_array($c)) {
            return '';
        }
        if (is_string($c['url'] ?? null) && $c['url'] !== '') {
            return $c['url'];
        }
        $s = static fn(mixed $v): string => rawurlencode(is_scalar($v) ? (string) $v : '');

        return 'postgres://' . $s($c['username'] ?? '') . ':' . $s($c['password'] ?? '') . '@' . $s($c['host'] ?? '127.0.0.1') . ':' . $s($c['port'] ?? '5432') . '/' . $s($c['database'] ?? '');
    }
}
