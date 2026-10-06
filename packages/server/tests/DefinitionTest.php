<?php

declare(strict_types=1);

namespace Accord\Server\Tests;

use Accord\Core\Schema;
use Accord\Server\Access;
use Accord\Server\AccordServer;
use Accord\Server\Auth;
use Accord\Server\Limits;
use Accord\Server\RateLimit;
use Accord\Server\ScopedRecord;
use PHPUnit\Framework\TestCase;

final class DefinitionTest extends TestCase
{
    private static function schema(): Schema
    {
        return Schema::define(['dossier' => ['agent' => Schema::lww()], 'note' => ['text' => Schema::lww()]]);
    }

    private static function access(): \Closure
    {
        return static fn(array $claims): Access => new Access(read: [], write: []);
    }

    public function testEveryRecordTypeNeedsAScopeFunction(): void
    {
        $this->expectExceptionMessage('no scope function for record type "note"');
        AccordServer::define(self::schema(), ['dossier' => static fn(ScopedRecord $r): array => []], self::access(), Auth::hs256('secret'));
    }

    public function testAScopeFunctionForAnUnknownTypeIsRefused(): void
    {
        $this->expectExceptionMessage('scope function for unknown record type "invoice"');
        $fn = static fn(ScopedRecord $r): array => [];
        AccordServer::define(self::schema(), ['dossier' => $fn, 'note' => $fn, 'invoice' => $fn], self::access(), Auth::hs256('secret'));
    }

    public function testDefaults(): void
    {
        $fn = static fn(ScopedRecord $r): array => ScopedRecord::key('agent', $r->fields['agent'] ?? null);
        $def = AccordServer::define(self::schema(), ['dossier' => $fn, 'note' => $fn], self::access(), Auth::hs256('secret', issuer: 'me'));
        self::assertSame(500, $def->limits->maxPushOps);
        self::assertSame(1000, $def->limits->maxPullLimit);
        self::assertSame(2000, $def->limits->maxScopeDelta);
        self::assertSame(5 * 1024 * 1024, $def->limits->maxBodyBytes);
        self::assertSame(86_400_000, $def->limits->maxSkewMs);
        self::assertSame(600.0, $def->rateLimit?->perDevice->perMinute);
        self::assertSame(1800.0, $def->rateLimit->perUser->burst);
        self::assertSame(20, $def->compaction->minOps);
        self::assertSame(30 * 86_400_000.0, $def->compaction->deviceTtlMs());
        self::assertSame(['agent:a'], ($def->scopes['dossier'])(new ScopedRecord('dossier:1', ['agent' => 'a'])));
        self::assertSame([], ($def->scopes['dossier'])(new ScopedRecord('dossier:1', ['agent' => 3])));
    }

    public function testRateLimitsCanBeDisabled(): void
    {
        $fn = static fn(ScopedRecord $r): array => [];
        self::assertNull(AccordServer::define(self::schema(), ['dossier' => $fn, 'note' => $fn], self::access(), Auth::hs256('s'), rateLimit: false)->rateLimit);
    }

    public function testInvalidSettingsAreRefused(): void
    {
        foreach ([
            static fn(): mixed => new RateLimit(0),
            static fn(): mixed => new Limits(maxPushOps: 0),
            static fn(): mixed => new Limits(maxScopeDelta: -1),
            static fn(): mixed => Auth::hs256(''),
            static fn(): mixed => Auth::jwks('file:///etc/passwd'),
        ] as $i => $make) {
            try {
                $make();
                self::fail("case $i was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
