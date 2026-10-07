# Mixed-server fleet

Milestone P3 of the PHP port (plan-php.md §0 point 3): one PostgreSQL database, the TypeScript
reference server and the PHP server running on it **at the same time**, and TypeScript devices whose
every request goes to a randomly chosen server over a network that loses requests and responses.

```sh
ACCORD_APP_DIR=/path/to/accord/app php/interop/run.sh            # seeds 1,2,3
ACCORD_FLEET_SEEDS=1,2,3,4,5,6,7,8,9,10 ACCORD_APP_DIR=… php/interop/run.sh
```

Needs Docker (unless `ACCORD_DATABASE_URL` is set), `psql`, `ss`, PHP 8.3+ with `pdo_pgsql` and
`composer install` done in `php/`, Node 22+ and the Accord workspace (`ACCORD_APP_DIR`, the `app/`
directory of crossben/accordsync, after `pnpm install`). The workspace sources are used, not npm:
they carry migrations 0006 and 0007 and fixes not yet released.

## What one seed does

`run.sh`, per seed:

1. creates a fresh database `accord_fleet_<seed>` (PostgreSQL in a container
   `accord-php-fleet-pg` on port 55471, or the server at `ACCORD_DATABASE_URL`, whose user must be
   allowed to create databases);
2. migrates it with **one** implementation: even seeds the TypeScript migrator
   (`tools/conformance/ts-migrate.mts`), odd seeds `tools/conformance/migrate.php`; then runs the
   other migrator and checks it found nothing to do (PHP prints "up to date"; the
   `kysely_migration` ledger must be unchanged, also after both servers started);
3. starts the TypeScript reference (`app/conformance/reference-server.ts`, sync 8841, control
   8842) and the PHP server (`tools/conformance/{server,control}.php` under `php -S`, 8843/8844),
   both with the conformance profile: same HS256 secret, so a token from either control API works on
   both;
4. runs `fleet.mts`, then stops both servers by the PIDs listening on their ports (`php -S` with
   workers is several processes) and drops the database.

`fleet.mts`:

- 4 devices (`@accordsync/client`, `MemoryStorage`), 2 users (awa, moussa) sharing zone `dakar`;
  records `dossier:1`, `dossier:2`, `dossier:é` in the zone; `dossier:4` (moussa's, zone `thies`);
  `dossier:5` (moussa's, zone `kaolack`). `pushBatch`/`pullLimit` follow the profile limits.
- Every request picks TS or PHP at random; 25% of requests are lost before the server sees them or
  after it applied them.
- 200 seeded steps: `inc`, `add`/`remove` on a set mixing numbers and strings (`0`, `2.5`, `1e21`,
  `"1"`, `""`, `"é"`, `"😀"`), `assign` of `null`, `2.5`, `1e21`, `{"10": true, "a": 1e21, "n": k}`,
  `"é"`, `""`, `"😀"`; one device syncs, or **all four sync concurrently** (concurrent pushes and
  pulls on both servers).
- Step 40: `dossier:5` moves into `dakar` (a feed scope row: it enters awa's devices).
- Step 60: awa gets a new token with zone `thies` (scope delta: `dossier:4` enters). Step 130: back
  to `dakar` only (exit; awa's pending writes to `dossier:4` are refused "out of scope"). Step 160:
  `thies` again. Tokens come from a random server's control API.
- Step 100: heal, settle 3 rounds; one random device then pushes an op whose response is lost, the
  others settle, that device is aged past the TTL (`/age-device`, random server) and compaction runs
  through a **random server's** control API: it must fold > 0 records. The aged device's retry of a
  folded op must be acknowledged (the server checks `compacted_ops.op_hash`); as a retired device it then
  resyncs. Then the network breaks again.
- End: heal, settle 3 rounds; every device must have nothing pending, byte-identical
  `canonicalJson({record: read})` snapshots containing all five records, no refusal other than
  "out of scope" (or its lost-response retry, "numbered above"), and **both** servers must have served pushes and pulls (counts printed).

`ACCORD_FLEET_DEBUG=1` records each step, op and loss; the trace is printed when snapshots differ.

## Scope changes on a lossy network (fixed bug)

Scope changes (steps 60, 130, 160) happen while the network loses requests and responses. Both
servers used to commit a device's new `read_keys` in the same transaction as the pull that carried
the scope delta, so a lost answer lost the delta for good (seeds 1 and 10 diverged on `dossier:4` in
one 10-seed run). The delta now stays pending until the device pulls from a later cursor (ADR-0011,
update of 2026-10-07; migration `0007_pending_scope_delta`), on both servers. The fleet used to work
around it by pulling scope changes on a healed network (`ACCORD_FLEET_LOSSY_SCOPES=1` reproduced
it); that workaround is gone.

## Why a plain Node script, not vitest or PHPUnit

- Devices are the real TypeScript client, so the runner is Node anyway; PHPUnit would add a
  stdin/stdout protocol to drive Node processes (as the Dart/Python interop does) for no gain.
- The devices run in-process: the per-request server choice and losses are a custom `fetch`, and
  counting requests per server is a closure. One process per seed, `node:assert`, exit code.
- Loading `@accordsync/client`/`core` from `ACCORD_APP_DIR` by path (with tsx and the
  `@accordsync/source` condition, run from `app/conformance`, which has tsx) needs no package.json,
  lockfile or install here, and always uses the workspace sources (same as `ts-migrate.mts`).
- The servers are restarted per seed because the migrator alternates per seed on a fresh database.

## Planted bugs (PHP server, one at a time, restored after)

| Planted in `packages/server/src` | Fleet (10 seeds) | Conformance suite |
| --- | --- | --- |
| Pull horizon = `max(pos) + 1` instead of `accord_horizon()` | caught (needs concurrent syncs; 1 of 10 seeds) | caught (4–5 of 68 fail, 3 runs of 3; the `/hold-record` horizon test added 2026-10-07) |
| Push locks record rows without `for update` | caught (1 of 10 seeds) | caught (2 concurrency tests) |
| No `scope` feed row before the op that changes scopes | caught (seed 2) | caught (7 tests) |
| Compaction stores `sha1` instead of `Sync::opHash` | caught ("op id already used" refusal of the retired device's retry) | caught (1 test) |
| Pull saves the new `read_keys` without `delta_keys`/`delta_cursor` (lost scope delta) | caught (seed 3 of 10: snapshots differ; the TypeScript server serves the other half of the pulls) | caught (3 tests) |

The concurrency mutants are timing dependent: CI runs 10 seeds.
