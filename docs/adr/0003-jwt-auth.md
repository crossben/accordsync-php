# ADR-P03: authenticate with JWT, like the TypeScript server

**Status:** accepted

## Decision

Sync requests carry `Authorization: Bearer <jwt>` and `Accord-Device`, verified against the app's
JWKS (cached) with issuer and audience, using `firebase/php-jwt` 7. HS256 shared secrets are
accepted for development only, as in the TypeScript server. The Laravel and Symfony bridges do not
use the framework's session or guard in v1.

## Why

Existing clients (TypeScript, React Native, Flutter) already send JWTs; any other scheme would need a
client change. An app that wants Sanctum or Symfony Security can issue JWTs from them. Version 6 of
`firebase/php-jwt` is blocked by Composer's security advisories, so 7 is the floor.
