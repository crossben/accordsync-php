# ADR-P02: share the TypeScript server's database and migration ledger

**Status:** accepted

## Decision

The PHP server uses the exact PostgreSQL schema of `@accordsync/server`: same tables, columns,
functions (`accord_pos()`, `accord_horizon()`, `accord_xid_offset()`) and append-only trigger. It
records its migrations in the **same ledger** the TypeScript server uses, Kysely's
`kysely_migration` table (`name`, `timestamp`), under the same names (`0001_meta` …
`0006_compacted_op_hash`), and takes the same lock while migrating: Kysely's PostgreSQL adapter
takes the session advisory lock `pg_advisory_lock(3853314791062309107)` (released with
`pg_advisory_unlock`), not a row lock. The ledger tables and the `migration_lock` row are created as
Kysely creates them; the PHP migrator also locks that row `FOR UPDATE` inside its transaction, which
is harmless. (Corrected in P2: the first version of this ADR said Kysely locked the row.)

A database created by either server is recognised and upgraded by the other. A new migration is
always written in the Accord repository first, then ported under the same name.

## Why

Clients must not care which server they talk to, and the strongest proof of that is two servers
writing one database at the same time (the mixed-server fleet). Reusing Kysely's ledger needs no
change to the TypeScript server.
