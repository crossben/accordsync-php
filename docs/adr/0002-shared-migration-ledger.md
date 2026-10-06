# ADR-P02: share the TypeScript server's database and migration ledger

**Status:** accepted

## Decision

The PHP server uses the exact PostgreSQL schema of `@accordsync/server`: same tables, columns,
functions (`accord_pos()`, `accord_horizon()`, `accord_xid_offset()`) and append-only trigger. It
records its migrations in the **same ledger** the TypeScript server uses, Kysely's
`kysely_migration` table (`name`, `timestamp`), under the same names (`0001_meta` …
`0005_concurrent_pushes`), and takes the same lock (`kysely_migration_lock`, row `migration_lock`,
`FOR UPDATE`) while migrating.

A database created by either server is recognised and upgraded by the other. A new migration is
always written in the Accord repository first, then ported under the same name.

## Why

Clients must not care which server they talk to, and the strongest proof of that is two servers
writing one database at the same time (the mixed-server fleet). Reusing Kysely's ledger needs no
change to the TypeScript server.
