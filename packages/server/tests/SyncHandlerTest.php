<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Core\Schema;
use Accord\Server\Access;
use Accord\Server\AccordServer;
use Accord\Server\Auth;
use Accord\Server\Http\SyncHandler;
use Accord\Server\Limits;
use Accord\Server\ScopedRecord;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/** What the handler answers before it needs the database (the conformance suite covers the rest). */
final class SyncHandlerTest extends TestCase
{
    private static function handler(): SyncHandler
    {
        $def = AccordServer::define(
            Schema::define(['dossier' => ['agent' => Schema::lww()]]),
            ['dossier' => static fn(ScopedRecord $r): array => []],
            static fn(array $c): Access => new Access([], []),
            Auth::hs256('secret-secret-secret-secret-secret!'),
            cors: ['https://app.example.com'],
            limits: new Limits(maxBodyBytes: 10),
        );
        $f = new Psr17Factory();

        return AccordServer::handler($def, static fn(): \PDO => throw new \PDOException('no database'), $f, $f);
    }

    public function testHealthWithoutADatabaseIs503(): void
    {
        $r = self::handler()->handle((new Psr17Factory())->createServerRequest('GET', '/health'));
        self::assertSame(503, $r->getStatusCode());
        self::assertSame('{"status":"unavailable","reason":"database unreachable"}', (string) $r->getBody());
        self::assertSame('1', $r->getHeaderLine('Accord-Protocol'));
    }

    public function testBodyLimitComesBeforeAuthAndUnknownRoutesAre404(): void
    {
        $f = new Psr17Factory();
        $big = self::handler()->handle($f->createServerRequest('POST', '/v1/push')->withBody($f->createStream('{"ops":[  ]}')));
        self::assertSame(413, $big->getStatusCode());
        self::assertSame('{"error":"request body too large"}', (string) $big->getBody());
        $noAuth = self::handler()->handle($f->createServerRequest('POST', '/v1/push')->withBody($f->createStream('{"ops":[]}')));
        self::assertSame(401, $noAuth->getStatusCode());
        self::assertSame('{"error":"missing bearer token"}', (string) $noAuth->getBody());
        self::assertSame(404, self::handler()->handle($f->createServerRequest('GET', '/v2/pull'))->getStatusCode());
    }

    public function testCors(): void
    {
        $f = new Psr17Factory();
        $pre = self::handler()->handle($f->createServerRequest('OPTIONS', '/v1/push')
            ->withHeader('Origin', 'https://app.example.com')->withHeader('Access-Control-Request-Method', 'POST'));
        self::assertSame(204, $pre->getStatusCode());
        self::assertSame('https://app.example.com', $pre->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Authorization,Accord-Device,Content-Type', $pre->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('600', $pre->getHeaderLine('Access-Control-Max-Age'));
        self::assertSame('1', $pre->getHeaderLine('Accord-Protocol'));

        $get = self::handler()->handle($f->createServerRequest('GET', '/v1/pull')->withHeader('Origin', 'https://app.example.com'));
        self::assertSame(401, $get->getStatusCode());
        self::assertSame('https://app.example.com', $get->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Accord-Protocol', $get->getHeaderLine('Access-Control-Expose-Headers'));

        $evil = self::handler()->handle($f->createServerRequest('GET', '/v1/pull')->withHeader('Origin', 'https://evil.example'));
        self::assertFalse($evil->hasHeader('Access-Control-Allow-Origin'));
    }
}
