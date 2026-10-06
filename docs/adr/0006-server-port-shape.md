# ADR-P06: the PHP server is a literal port of sync.ts behind a PSR-15 handler

**Status:** accepted (P2)

## Decision

- `Accord\Server\Sync` and `Compactor` port `sync.ts` and `compact.ts` statement by statement, with
  the same SQL through PDO (native prepares, `?::text[]` / `?::jsonb` casts, `to_json()` to read
  `text[]` columns): advisory lock `0x4acc0d` shared for push and exclusive for compaction, record rows
  created then locked `FOR UPDATE` ordered by `record`, records processed in UTF-16 order (JavaScript's
  `sort()`), feed reads below `accord_horizon()` ending on transaction boundaries, pull under
  `repeatable read` retried on `40001` (20 attempts, jittered), `op_hash` = SHA-256 hex of canonical
  wire JSON.
- `AccordServer::define(schema, scopes, access, auth, cors, rateLimit, compaction, limits)` returns a
  checked `ServerDefinition` (every type has a scope function and vice versa). `AccordServer::handler()`
  returns a `RequestHandlerInterface` for `GET /health`, `POST /v1/push`, `GET /v1/pull`; it needs any
  PSR-17 response and stream factory. Request order matches Hono's: 413 before auth, then 401, 400
  (device header), 429, 403 (device owner), then the body (400). `cursor` and `limit` are parsed like
  JavaScript `Number()`.
- There is no `maxConcurrentPushes`: one request per PHP worker, so the worker pool bounds it.
- `/metrics` (Prometheus) is not ported in P2.
- `bin/accord migrate|compact <definition.php>` is the plain-PHP CLI until the bridges (P4).
