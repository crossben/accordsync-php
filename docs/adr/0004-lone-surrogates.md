# ADR-P04: hold JSON strings as WTF-8 so lone surrogates survive

**Status:** accepted (implemented in P1)

## Decision

The core decodes JSON with its own small parser. Strings become PHP strings in WTF-8: UTF-8, plus
the 3-byte encoding of an unpaired surrogate escape such as `\ud800`. The canonical encoder writes
those bytes back as `\ud800`, exactly as `JSON.stringify` does, and compares strings by UTF-16 code
unit. `json_decode` is not used for op values or snapshots.

## Why

JavaScript strings can hold lone surrogates, and the random vectors contain them on purpose. PHP's
`json_decode` rejects them (`JSON_ERROR_UTF16`), and it also cannot tell `{}` from `[]` or keep the
key `"10"` as a string in arrays. A dedicated parser fixes all three at the one place values enter
the core.

## Update (2026-10-07): the server refuses ops carrying one

PostgreSQL cannot store a lone surrogate (`jsonb` refuses it, `text` replaces it), so an op holding
one made the whole push fail with 500 and the client retried it forever. As the TypeScript server
(its `lonePath`), `Sync::push` now refuses such an op as `malformed op: lone surrogate in <path>`
(`op.value`, `op.value.x[2]`, `op.value (a key)`, `op.deps[1]`...) and applies the rest of the batch.
Every string and object key of the raw op is checked, ids included. In WTF-8 a lone surrogate is the
byte sequence `ED A0..BF xx`, which valid UTF-8 never contains, so the check is one byte regex. Keys
are visited in JavaScript's `Object.entries` order (array-index keys first, ascending) so the first
path found is the same as the TypeScript server's.

