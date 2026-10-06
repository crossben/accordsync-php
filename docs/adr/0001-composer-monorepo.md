# ADR-P01: one Composer monorepo, split for Packagist

**Status:** accepted

## Decision

The four packages (`accordsync/core`, `server`, `laravel`, `symfony`) live in one repository. The root
`composer.json` installs them through a path repository pinned to the current version, so every
package is developed and tested together. For release, each package directory is split into its own
read-only repository (`splitsh-lite` in CI, or `symplify/monorepo-builder`), which Packagist reads.

## Why

A server change usually touches the core and both bridges at once; one repository keeps them in one
commit and one CI run. Packagist needs one repository per package, which the split provides without
giving up the monorepo.
