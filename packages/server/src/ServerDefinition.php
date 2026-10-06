<?php

declare(strict_types=1);

namespace Accord\Server;

use Accord\Core\Schema;

/** A checked server definition: build it with {@see AccordServer::define()}. */
final readonly class ServerDefinition
{
    /**
     * @param array<string, \Closure(ScopedRecord): array<mixed>> $scopes record type → scope function (checked to return strings at each call)
     * @param \Closure(array<string, mixed>): Access $access
     * @param list<string> $cors
     */
    public function __construct(
        public Schema $schema,
        public array $scopes,
        public \Closure $access,
        public Auth $auth,
        public array $cors,
        public ?RateLimits $rateLimit,
        public Compaction $compaction,
        public Limits $limits,
    ) {}
}
