<?php

declare(strict_types=1);

namespace Accord\Laravel\Tests;

use Accord\Core\Schema;
use Accord\Server\Access;
use Accord\Server\AccordServer;
use Accord\Server\Auth;
use Accord\Server\Compaction;
use Accord\Server\ScopedRecord;
use Accord\Server\ServerDefinition;

/** An invokable definition class, as config('accord.definition') names it. */
final class Fixtures
{
    public const string SECRET = 'bridge-test-secret-at-least-32-bytes-long';

    public function __invoke(): ServerDefinition
    {
        return self::definition();
    }

    public static function definition(int $compactEveryMs = 0): ServerDefinition
    {
        return AccordServer::define(
            schema: Schema::define(['note' => ['owner' => Schema::lww()]]),
            scopes: ['note' => static fn(ScopedRecord $r): array => ScopedRecord::key('owner', $r->fields['owner'] ?? null)],
            access: static fn(array $claims): Access => new Access(read: ScopedRecord::key('owner', $claims['sub'] ?? null), write: ScopedRecord::key('owner', $claims['sub'] ?? null)),
            auth: Auth::hs256(self::SECRET),
            compaction: new Compaction(intervalMs: $compactEveryMs),
        );
    }
}
