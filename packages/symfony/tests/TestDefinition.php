<?php

declare(strict_types=1);

namespace Accord\Symfony\Tests;

use Accord\Core\Schema;
use Accord\Server\Access;
use Accord\Server\AccordServer;
use Accord\Server\Auth;
use Accord\Server\ScopedRecord;
use Accord\Server\ServerDefinition;
use Accord\Symfony\DefinitionProvider;
use Psr\SimpleCache\CacheInterface;

/** The app's `accord.definition` service in the test kernel; receives the PSR-16 cache by autowiring. */
final class TestDefinition implements DefinitionProvider
{
    public function __construct(public readonly CacheInterface $accordCache) {}

    public function define(): ServerDefinition
    {
        return AccordServer::define(
            schema: Schema::define(['note' => ['owner' => Schema::lww()]]),
            scopes: ['note' => static fn(ScopedRecord $r): array => ScopedRecord::key('owner', $r->fields['owner'] ?? null)],
            access: static fn(array $claims): Access => new Access(read: ScopedRecord::key('owner', $claims['sub'] ?? null), write: ScopedRecord::key('owner', $claims['sub'] ?? null)),
            auth: Auth::hs256('bridge-test-secret-at-least-32-bytes-long'),
        );
    }
}
