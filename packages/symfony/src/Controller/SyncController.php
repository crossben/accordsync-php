<?php

declare(strict_types=1);

namespace Accord\Symfony\Controller;

use Accord\Symfony\Accord;
use Nyholm\Psr7\ServerRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Hands a sync request to the PSR-15 handler, with the path relative to the configured prefix. */
final class SyncController
{
    public function __construct(private readonly Accord $accord) {}

    public function __invoke(Request $request): Response
    {
        $path = $request->attributes->get('_accord_path');
        $path = \is_string($path) ? $path : '/';
        $query = $request->server->get('QUERY_STRING');
        $query = \is_string($query) ? $query : '';
        parse_str($query, $params);
        $psr = (new ServerRequest($request->getMethod(), $query === '' ? $path : "$path?$query", self::headers($request->headers->all()), $request->getContent(), '1.1', $request->server->all()))
            ->withQueryParams($params);
        $response = $this->accord->handler()->handle($psr);

        return new Response((string) $response->getBody(), $response->getStatusCode(), $response->getHeaders());
    }

    /**
     * The client's headers, minus the ones PHP derives from them (`php-auth-*`, decoded from a Basic
     * Authorization header) and values PSR-7 refuses (control characters): the handler never reads
     * them, and a refused value must not turn a 401 into a 500.
     *
     * @param array<string, list<string|null>> $headers
     *
     * @return array<string, list<string>>
     */
    private static function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $values) {
            if (str_starts_with($name, 'php-auth-')) {
                continue;
            }
            $values = array_values(array_filter($values, static fn(?string $v): bool => $v !== null && preg_match("@^[ \t\x21-\x7E\x80-\xFF]*$@D", $v) === 1));
            if ($values !== []) {
                $out[$name] = $values;
            }
        }

        return $out;
    }
}
