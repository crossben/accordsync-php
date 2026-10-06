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
        );

        return new JsonResponse($body, $status);
    }
}
