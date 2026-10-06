<?php

declare(strict_types=1);

namespace Accord\Server;

use Psr\SimpleCache\CacheInterface;

/**
 * How sync requests authenticate: `Authorization: Bearer <jwt>` checked against the app's JWKS
 * (production) or a shared HS256 secret (development and tests), with issuer and audience (ADR-P03).
 */
final readonly class Auth
{
    /**
     * @param ?\Closure(string): string $fetch downloads the JWKS document (tests inject a fixture)
     */
    private function __construct(
        public ?string $jwksUrl,
        #[\SensitiveParameter]
        public ?string $hs256Secret,
        public ?string $issuer,
        public ?string $audience,
        public ?CacheInterface $cache,
        public ?\Closure $fetch,
        public int $cacheTtlSeconds,
    ) {}

    /**
     * Verifies tokens with the keys published at `$jwksUrl`. Keys are cached for `$cacheTtlSeconds`
     * (in the process, and in `$cache` when given so PHP-FPM workers share them); a token signed by
     * an unknown key id refetches the set, at most once every 30 seconds.
     *
     * @param ?\Closure(string): string $fetch
     */
    public static function jwks(
        string $jwksUrl,
        ?string $issuer = null,
        ?string $audience = null,
        ?CacheInterface $cache = null,
        ?\Closure $fetch = null,
        int $cacheTtlSeconds = 600,
    ): self {
        if (preg_match('#^https?://#i', $jwksUrl) !== 1) {
            throw new \InvalidArgumentException("auth: jwksUrl must be an http(s) URL, got \"$jwksUrl\"");
        }

        return new self($jwksUrl, null, $issuer, $audience, $cache, $fetch, $cacheTtlSeconds);
    }

    /** Shared-secret HS256 tokens: for development and tests. Prefer {@see jwks()} in production. */
    public static function hs256(#[\SensitiveParameter] string $secret, ?string $issuer = null, ?string $audience = null): self
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('auth: the HS256 secret must not be empty');
        }

        return new self(null, $secret, $issuer, $audience, null, null, 0);
    }
}
