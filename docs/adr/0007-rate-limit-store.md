# ADR-P07: rate limits through a small store interface

**Status:** accepted (P2)

## Decision

`RateLimit\RateLimiter` (`take(key, limit, nowMs): msToWait`, `clear()`) holds the token buckets; the
arithmetic (`TokenBucket`) is the TypeScript `RateLimiter`'s. Implementations: `InMemoryRateLimiter`
(one process: tests, RoadRunner/Swoole), `FileRateLimiter` (one JSON file under `flock`, shared by all
workers of one host; used by the conformance harness). Production behind PHP-FPM on several hosts
uses the framework cache through the Laravel and Symfony bridges (P4).

## Why

PHP-FPM workers share no memory, and APCu is often absent (it is on the dev machine). Unlike the Node
server, a PHP worker checks the limit only when it picks the request up, so a burst queued at the
socket is checked over time and refills meanwhile; the conformance burst still gets 429s (about 35 of
150) with 8 workers.
