<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Server\Auth;
use Accord\Server\AuthError;
use Accord\Server\JwtVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class JwtVerifierTest extends TestCase
{
    private const string SECRET = 'a-test-secret-of-at-least-32-bytes!!';

    /** @var array<string, array{pem: string, jwk: array<string, string>}> */
    private static array $keys = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['k1', 'k2'] as $kid) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($key);
            openssl_pkey_export($key, $pem);
            $d = openssl_pkey_get_details($key);
            self::assertIsArray($d);
            $b64 = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            self::$keys[$kid] = ['pem' => (string) $pem, 'jwk' => ['kty' => 'RSA', 'kid' => $kid, 'alg' => 'RS256', 'use' => 'sig', 'n' => $b64($d['rsa']['n']), 'e' => $b64($d['rsa']['e'])]];
        }
    }

    protected function setUp(): void
    {
        JwtVerifier::clearMemo();
    }

    /** @param array<string, mixed> $claims */
    private static function hs(array $claims, string $secret = self::SECRET): string
    {
        return 'Bearer ' . JWT::encode($claims + ['exp' => time() + 60], $secret, 'HS256');
    }

    /** @param array<string, mixed> $claims */
    private static function rs(string $kid, array $claims): string
    {
        return 'Bearer ' . JWT::encode($claims + ['exp' => time() + 60], self::$keys[$kid]['pem'], 'RS256', $kid);
    }

    private static function refused(JwtVerifier $v, ?string $authorization): string
    {
        try {
            $v->verify($authorization);
        } catch (AuthError $e) {
            return $e->getMessage();
        }
        self::fail('accepted: ' . $authorization);
    }

    public function testHs256(): void
    {
        $v = new JwtVerifier(Auth::hs256(self::SECRET, issuer: 'app', audience: 'sync'));
        $claims = $v->verify(self::hs(['sub' => 'alice', 'iss' => 'app', 'aud' => ['x', 'sync'], 'zones' => ['a']]));
        self::assertSame('alice', $claims['sub']);
        self::assertSame(['a'], $claims['zones']);

        self::assertSame('missing bearer token', self::refused($v, null));
        self::assertSame('missing bearer token', self::refused($v, 'Basic abc'));
        self::assertStringStartsWith('invalid token', self::refused($v, 'Bearer not-a-jwt'));
        self::assertStringStartsWith('invalid token', self::refused($v, self::hs(['sub' => 'a', 'iss' => 'app', 'aud' => 'sync'], 'another-secret-of-at-least-32-bytes!!')));
        self::assertStringStartsWith('invalid token', self::refused($v, self::hs(['sub' => 'a', 'iss' => 'other', 'aud' => 'sync'])));
        self::assertStringStartsWith('invalid token', self::refused($v, self::hs(['sub' => 'a', 'iss' => 'app', 'aud' => 'web'])));
        self::assertStringStartsWith('invalid token', self::refused($v, self::hs(['sub' => 'a', 'iss' => 'app', 'aud' => 'sync', 'exp' => time() - 10])));
        self::assertSame('token has no "sub" claim', self::refused($v, self::hs(['iss' => 'app', 'aud' => 'sync'])));
        self::assertSame('token has no "sub" claim', self::refused($v, self::hs(['sub' => '', 'iss' => 'app', 'aud' => 'sync'])));
        // An RS256 token is never checked against the HS256 secret.
        self::assertStringStartsWith('invalid token', self::refused($v, self::rs('k1', ['sub' => 'a', 'iss' => 'app', 'aud' => 'sync'])));
    }

    public function testJwksIsFetchedOnceAndCached(): void
    {
        $fetches = 0;
        $set = ['keys' => [self::$keys['k1']['jwk']]];
        $now = 1_000;
        $v = new JwtVerifier(Auth::jwks('https://auth.test/jwks.json', issuer: 'app', fetch: static function (string $url) use (&$fetches, &$set): string {
            self::assertSame('https://auth.test/jwks.json', $url);
            $fetches++;

            return json_encode($set, JSON_THROW_ON_ERROR);
        }), static function () use (&$now): int {
            return $now;
        });
        for ($i = 0; $i < 3; $i++) {
            self::assertSame('alice', $v->verify(self::rs('k1', ['sub' => 'alice', 'iss' => 'app']))['sub']);
        }
        self::assertSame(1, $fetches, 'cached');

        // A key rotation: the unknown kid refetches the set, at most once per cooldown.
        $set = ['keys' => [self::$keys['k1']['jwk'], self::$keys['k2']['jwk']]];
        self::assertStringStartsWith('invalid token', self::refused($v, self::rs('k2', ['sub' => 'a', 'iss' => 'app'])));
        self::assertSame(1, $fetches, 'within the cooldown');
        $now += 31;
        self::assertSame('bob', $v->verify(self::rs('k2', ['sub' => 'bob', 'iss' => 'app']))['sub']);
        self::assertSame(2, $fetches);

        // After the TTL the set is fetched again.
        $now += 601;
        $v->verify(self::rs('k1', ['sub' => 'alice', 'iss' => 'app']));
        self::assertSame(3, $fetches);

        // HS256 tokens are refused by a JWKS verifier (no algorithm confusion).
        self::assertStringStartsWith('invalid token', self::refused($v, self::hs(['sub' => 'a', 'iss' => 'app'])));
    }

    public function testJwksIsSharedThroughAPsr16Cache(): void
    {
        $cache = new ArrayCache();
        $fetches = 0;
        $fetch = static function () use (&$fetches): string {
            $fetches++;

            return json_encode(['keys' => [self::$keys['k1']['jwk']]], JSON_THROW_ON_ERROR);
        };
        $make = static fn(): JwtVerifier => new JwtVerifier(Auth::jwks('https://auth.test/jwks.json', cache: $cache, fetch: $fetch));
        $make()->verify(self::rs('k1', ['sub' => 'a']));
        JwtVerifier::clearMemo(); // another worker process
        $make()->verify(self::rs('k1', ['sub' => 'a']));
        self::assertSame(1, $fetches);
    }
}

/** A minimal PSR-16 cache for the test. */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->items[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    /** @param iterable<string> $keys */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->get($k, $default);
        }

        return $out;
    }

    /** @param iterable<string, mixed> $values */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $k => $v) {
            $this->set($k, $v);
        }

        return true;
    }

    /** @param iterable<string> $keys */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $k) {
            $this->delete($k);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->items[$key]);
    }
}
