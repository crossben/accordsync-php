<?php

declare(strict_types=1);

namespace Accord\Server\Http;

use Accord\Core\Hlc;
use Accord\Core\Json;
use Accord\Core\JsonObject;
use Accord\Core\Protocol;
use Accord\Server\AuthError;
use Accord\Server\BadRequest;
use Accord\Server\Caller;
use Accord\Server\Database;
use Accord\Server\Forbidden;
use Accord\Server\JwtVerifier;
use Accord\Server\RateLimit\RateLimiter;
use Accord\Server\ServerDefinition;
use Accord\Server\Sync;
use Accord\Server\TooManyRequests;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /health`, `POST /v1/push`, `GET /v1/pull` as a PSR-15 handler: same status codes, bodies and
 * headers as the TypeScript server's `app.ts`. Mount it at the root of a path prefix (the bridges
 * strip their prefix before calling it).
 */
final class SyncHandler implements RequestHandlerInterface
{
    private readonly Sync $sync;

    private readonly JwtVerifier $verifier;

    /** @var \Closure(): int */
    private readonly \Closure $now;

    /** @param ?\Closure(): int $now physical time in ms */
    public function __construct(
        private readonly ServerDefinition $def,
        private readonly Database $db,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly RateLimiter $rateLimiter,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): int => (int) floor(microtime(true) * 1000);
        $this->sync = new Sync($def, $db, $this->now);
        $this->verifier = new JwtVerifier($def->auth);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->route($request);
        } catch (AuthError $e) {
            $response = $this->error(401, $e->getMessage());
        } catch (Forbidden $e) {
            $response = $this->error(403, $e->getMessage());
        } catch (BadRequest $e) {
            $response = $this->error(400, $e->getMessage());
        } catch (PayloadTooLarge $e) {
            $response = $this->error(413, $e->getMessage());
        } catch (TooManyRequests $e) {
            $response = $this->error(429, 'too many requests')
                ->withHeader('Retry-After', (string) (int) ceil($e->retryAfterMs / 1000));
        } catch (\Throwable $e) {
            error_log('accord: ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            $response = $this->error(500, 'internal error');
        }

        return $this->cors($request, $response)->withHeader('Accord-Protocol', (string) Protocol::VERSION);
    }

    private function route(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        if ($method === 'OPTIONS' && $this->def->cors !== [] && $request->hasHeader('Access-Control-Request-Method')) {
            return $this->preflight($request);
        }
        if ($path === '/health' && ($method === 'GET' || $method === 'HEAD')) {
            return $this->health();
        }
        if (str_starts_with($path, '/v1/')) {
            $this->checkBodySize($request);
        }
        if ($path === '/v1/push' && $method === 'POST') {
            $caller = $this->caller($request);
            $body = $this->body($request);

            return $this->json(200, Json::canonical(self::pushResponse($this->sync->push($caller, $body))));
        }
        if ($path === '/v1/pull' && ($method === 'GET' || $method === 'HEAD')) {
            $caller = $this->caller($request);
            $query = $request->getQueryParams();
            $cursor = Sync::jsNumber(self::param($query, 'cursor') ?? '0');
            $limit = Sync::jsNumber(self::param($query, 'limit') ?? '500');
            if ($cursor === null || !Sync::isSafeInteger($cursor) || $cursor < 0) {
                throw new BadRequest('cursor must be an integer ≥ 0');
            }
            if ($limit === null || !Sync::isSafeInteger($limit) || $limit < 1) {
                throw new BadRequest('limit must be an integer ≥ 1');
            }
            $page = $this->sync->pull($caller, (int) $cursor, (int) $limit);
            $out = new JsonObject();
            foreach ($page as $k => $v) {
                $out->set($k, $v);
            }

            return $this->json(200, Json::canonical($out));
        }

        return $this->error(404, 'not found');
    }

    private function health(): ResponseInterface
    {
        try {
            $this->db->run('select 1');

            return $this->json(200, '{"status":"ok","protocolVersion":' . Protocol::VERSION . '}');
        } catch (\Throwable) {
            return $this->json(503, '{"status":"unavailable","reason":"database unreachable"}');
        }
    }

    /** Who is calling: token (401), device header (400), rate limits (429), device owner (403). */
    private function caller(ServerRequestInterface $request): Caller
    {
        $claims = $this->verifier->verify($request->hasHeader('Authorization') ? $request->getHeaderLine('Authorization') : null);
        $deviceId = $request->getHeaderLine('Accord-Device');
        try {
            Hlc::assertNode($deviceId);
        } catch (\Throwable) {
            throw new BadRequest('Accord-Device header must be a device id ([A-Za-z0-9_-]{1,64})');
        }
        $sub = Database::str($claims['sub']);
        $limits = $this->def->rateLimit;
        if ($limits !== null) {
            $now = ($this->now)();
            $wait = max(
                $this->rateLimiter->take("device:$deviceId", $limits->perDevice, $now),
                $this->rateLimiter->take("user:$sub", $limits->perUser, $now),
            );
            if ($wait > 0) {
                throw new TooManyRequests($wait);
            }
        }
        $access = ($this->def->access)($claims);
        $caller = new Caller($sub, $deviceId, self::keys($access->read), self::keys($access->write));
        $this->sync->touchDevice($caller);

        return $caller;
    }

    /**
     * @param array<array-key, mixed> $keys
     *
     * @return list<string>
     */
    private static function keys(array $keys): array
    {
        return array_values(array_map(strval(...), array_filter($keys, \is_string(...))));
    }

    private function checkBodySize(ServerRequestInterface $request): void
    {
        $max = $this->def->limits->maxBodyBytes;
        $declared = $request->getHeaderLine('Content-Length');
        if (($declared !== '' && ctype_digit($declared) && (int) $declared > $max) || ($request->getBody()->getSize() ?? 0) > $max) {
            throw new PayloadTooLarge();
        }
    }

    /** @return list<mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $text = $stream->getContents();
        if (\strlen($text) > $this->def->limits->maxBodyBytes) {
            throw new PayloadTooLarge();
        }
        try {
            $body = Json::decode($text);
        } catch (\Throwable) {
            throw new BadRequest('body must be JSON');
        }
        if (!$body instanceof JsonObject || $body->keys() !== ['ops'] || !\is_array($body->get('ops')) || !array_is_list($body->get('ops'))) {
            throw new BadRequest('body must be { "ops": [...] }');
        }

        return $body->get('ops');
    }

    /**
     * @param array{acked: list<string>, refused: list<array{op_id: string, reason: string}>} $r
     */
    private static function pushResponse(array $r): JsonObject
    {
        return new JsonObject([
            'acked' => $r['acked'],
            'refused' => array_map(static fn(array $x): JsonObject => new JsonObject($x), $r['refused']),
        ]);
    }

    /** @param array<array-key, mixed> $query */
    private static function param(array $query, string $name): ?string
    {
        if (!\array_key_exists($name, $query)) {
            return null;
        }
        $v = $query[$name];

        return \is_string($v) ? $v : 'NaN';
    }

    private function preflight(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->responses->createResponse(204);
        $origin = $request->getHeaderLine('Origin');
        if (\in_array($origin, $this->def->cors, true)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Max-Age', '600')
                ->withHeader('Access-Control-Allow-Methods', 'GET,HEAD,PUT,POST,DELETE,PATCH')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization,Accord-Device,Content-Type');
        }

        return $response->withHeader('Vary', 'Origin');
    }

    /** Hono's `cors()` with an origin list: allowed origins are echoed, `Accord-Protocol` exposed. */
    private function cors(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->def->cors === [] || $response->hasHeader('Access-Control-Allow-Origin')) {
            return $response;
        }
        $origin = $request->getHeaderLine('Origin');
        if (\in_array($origin, $this->def->cors, true)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Expose-Headers', 'Accord-Protocol');
        }

        return $response->withHeader('Vary', 'Origin');
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return $this->json($status, Json::canonical(new JsonObject(['error' => $message])));
    }

    private function json(int $status, string $body): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream($body));
    }
}
