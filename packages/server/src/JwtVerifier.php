<?php

declare(strict_types=1);

namespace Accord\Server;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/** Verifies `Authorization: Bearer <jwt>` and returns its claims. Port of `auth.ts`. */
final class JwtVerifier
{
    private const array ASYMMETRIC = ['RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512', 'ES256', 'ES384', 'ES256K', 'EdDSA'];

    private const int REFETCH_COOLDOWN_SECONDS = 30;

    /** @var array<string, array{keys: list<array<string, mixed>>, at: int}> JWKS documents by URL, per process */
    private static array $memo = [];

    /** @param ?\Closure(): int $clock seconds, for tests */
    public function __construct(private readonly Auth $auth, private readonly ?\Closure $clock = null) {}

    /** @return array<string, mixed> the verified claims; `sub` is a non-empty string */
    public function verify(?string $authorization): array
    {
        if (preg_match('/^Bearer (.+)$/D', $authorization ?? '', $m) !== 1) {
            throw new AuthError('missing bearer token');
        }
        $token = $m[1];
        try {
            $payload = JWT::decode($token, $this->keyFor($token));
            $claims = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($claims)) {
                throw new \UnexpectedValueException('claims must be an object');
            }
            $this->checkClaims($claims);
        } catch (AuthError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AuthError('invalid token: ' . $e->getMessage(), 0, $e);
        }
        if (!\is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new AuthError('token has no "sub" claim');
        }
        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /** @param array<array-key, mixed> $claims */
    private function checkClaims(array $claims): void
    {
        if ($this->auth->issuer !== null && ($claims['iss'] ?? null) !== $this->auth->issuer) {
            throw new AuthError('invalid token: unexpected "iss" claim value');
        }
        if ($this->auth->audience !== null) {
            $aud = $claims['aud'] ?? null;
            $ok = \is_string($aud) ? $aud === $this->auth->audience : \is_array($aud) && \in_array($this->auth->audience, $aud, true);
            if (!$ok) {
                throw new AuthError('invalid token: unexpected "aud" claim value');
            }
        }
    }

    private function keyFor(string $token): Key
    {
        if ($this->auth->hs256Secret !== null) {
            return new Key($this->auth->hs256Secret, 'HS256');
        }
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $token)[0]), true);
        if (!\is_array($header) || !\is_string($header['alg'] ?? null) || !\in_array($header['alg'], self::ASYMMETRIC, true)) {
            throw new \UnexpectedValueException('unsupported "alg"');
        }
        $kid = isset($header['kid']) && \is_string($header['kid']) ? $header['kid'] : null;
        $key = $this->find($this->keySet(false), $kid, $header['alg']);
        if ($key === null && $this->mayRefetch()) {
            $key = $this->find($this->keySet(true), $kid, $header['alg']);
        }

        return $key ?? throw new \UnexpectedValueException('no applicable key found in the JSON Web Key Set');
    }

    /** @param list<array<string, mixed>> $keys */
    private function find(array $keys, ?string $kid, string $alg): ?Key
    {
        $candidates = array_values(array_filter(
            $keys,
            static fn(array $k): bool => ($kid === null || ($k['kid'] ?? null) === $kid)
                && (!isset($k['alg']) || $k['alg'] === $alg)
                && (!isset($k['use']) || $k['use'] === 'sig'),
        ));
        // Like jose: without a kid, the key must be unambiguous.
        if (\count($candidates) !== 1) {
            return null;
        }

        return JWK::parseKey($candidates[0], $alg);
    }

    /** @return list<array<string, mixed>> */
    private function keySet(bool $refresh): array
    {
        $url = (string) $this->auth->jwksUrl;
        $now = $this->now();
        $cacheKey = 'accord.jwks.' . hash('sha256', $url);
        if (!$refresh) {
            $memo = self::$memo[$url] ?? null;
            if ($memo !== null && $now - $memo['at'] < $this->auth->cacheTtlSeconds) {
                return $memo['keys'];
            }
            $cached = $this->auth->cache?->get($cacheKey);
            if (\is_array($cached) && \is_int($cached['at'] ?? null) && \is_array($cached['keys'] ?? null) && $now - $cached['at'] < $this->auth->cacheTtlSeconds) {
                /** @var array{keys: list<array<string, mixed>>, at: int} $cached */
                self::$memo[$url] = $cached;

                return $cached['keys'];
            }
        }
        $doc = json_decode($this->download($url), true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($doc) || !\is_array($doc['keys'] ?? null) || !array_is_list($doc['keys'])) {
            throw new \UnexpectedValueException('the JWKS document has no "keys" array');
        }
        $keys = array_values(array_filter($doc['keys'], \is_array(...)));
        /** @var list<array<string, mixed>> $keys */
        $entry = ['keys' => $keys, 'at' => $now];
        self::$memo[$url] = $entry;
        $this->auth->cache?->set($cacheKey, $entry, $this->auth->cacheTtlSeconds);

        return $keys;
    }

    private function mayRefetch(): bool
    {
        $at = self::$memo[(string) $this->auth->jwksUrl]['at'] ?? 0;

        return $this->now() - $at >= self::REFETCH_COOLDOWN_SECONDS;
    }

    private function download(string $url): string
    {
        if ($this->auth->fetch !== null) {
            return ($this->auth->fetch)($url);
        }
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => false, 'header' => "Accept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \UnexpectedValueException("could not fetch the JWKS from $url");
        }

        return $body;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /** Forgets every JWKS fetched by this process (tests). */
    public static function clearMemo(): void
    {
        self::$memo = [];
    }
}
