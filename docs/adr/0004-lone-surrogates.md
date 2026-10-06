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
