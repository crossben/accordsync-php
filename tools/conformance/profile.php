<?php

declare(strict_types=1);

/*
 * The conformance profile (accord app/conformance/PROFILE.md, profile.json) as a PHP server
 * definition. Included by server.php and control.php.
 */

use Accord\Core\Schema;
use Accord\Server\Access;
use Accord\Server\AccordServer;
use Accord\Server\Auth;
use Accord\Server\Compaction;
use Accord\Server\Limits;
use Accord\Server\RateLimit;
use Accord\Server\RateLimits;
use Accord\Server\ScopedRecord;

const ACCORD_CONFORMANCE_SECRET = 'accord-conformance-secret-at-least-32-bytes';
const ACCORD_CONFORMANCE_ISSUER = 'accord-conformance';

/**
 * @param mixed $v
 *
 * @return list<string>
 */
function accord_conformance_strings(mixed $v): array
{
    return \is_array($v) ? array_values(array_filter($v, \is_string(...))) : [];
}

return AccordServer::define(
    schema: Schema::define([
        'dossier' => [
            'agent' => Schema::lww(),
            'zone' => Schema::lww(),
            'client_name' => Schema::lww(),
            'status' => Schema::conflict(),
            'visits' => Schema::counter(),
            'docs' => Schema::set(),
        ],
    ]),
    scopes: [
        'dossier' => static fn(ScopedRecord $r): array => [
            ...ScopedRecord::key('agent', $r->fields['agent'] ?? null),
            ...ScopedRecord::key('zone', $r->fields['zone'] ?? null),
        ],
    ],
    access: static function (array $claims): Access {
        $write = [...ScopedRecord::key('agent', $claims['sub']), ...array_map(static fn(string $z): string => "zone:$z", accord_conformance_strings($claims['zones'] ?? null))];
        $readonly = array_map(static fn(string $z): string => "zone:$z", accord_conformance_strings($claims['readonly_zones'] ?? null));

        return new Access(read: [...$write, ...$readonly], write: $write);
    },
    auth: Auth::hs256(ACCORD_CONFORMANCE_SECRET, issuer: ACCORD_CONFORMANCE_ISSUER),
    rateLimit: new RateLimits(perDevice: new RateLimit(6000, burst: 100), perUser: new RateLimit(12000, burst: 200)),
    compaction: new Compaction(deviceTtlDays: 30, intervalMs: 0, minOps: 2),
    limits: new Limits(maxBodyBytes: 16384, maxScopeDelta: 3, maxPushOps: 20, maxPullLimit: 10, maxSkewMs: 60000),
);
