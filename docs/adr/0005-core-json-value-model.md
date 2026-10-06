# ADR-P05: the core's JSON value model and PHP API shape

**Status:** accepted (P1)

## Decision

- **Values.** JSON values in the core are `null`, `bool`, `int`, `float`, `string` (WTF-8, ADR-P04),
  a PHP **list** for an array, and `Accord\Core\JsonObject` for an object. A PHP array that is not a
  list is refused (by `Json::canonical` and `LocalWriter::assign`), never guessed at: `[]` would be
  ambiguous and `"10"` would become an int key. `JsonObject` stores keys in a PHP array and casts
  them back to strings on the way out, which is lossless (PHP only converts canonical integer
  strings).
- **Numbers.** The parser returns an `int` for a literal without fraction or exponent within ±2^53,
  and a `float` otherwise (so `9007199254740993` reads as JavaScript reads it). Ints and floats are
  the same number everywhere it matters: set elements compare as floats (`1` and `1.0` are one
  element, `"1"` is another), `by` accepts a whole-number float, and `Json::number` prints both like
  `Number.prototype.toString` (shortest round-trip digits via the first `sprintf('%.Ne')` precision
  that reads back exactly, then ECMA-262's layout rules).
- **Never-written fields** read as `Absent::Value` from `FieldState::read()` and are left out of
  `Replica::read()`, like JavaScript's `undefined`.
- **Schema helpers** are static: `Schema::lww()`, `Schema::counter()`, `Schema::set()`,
  `Schema::conflict()`. Namespaced functions (`lww()` as in plan §3) need a Composer `files`
  autoload entry, which only takes effect after a root `composer update`; they can be added in P2
  alongside the server package without changing anything here.
- **String order** everywhere (op ids, record ids, set elements, object keys, conflict fields) goes
  through `Utf16::compare`: byte order below lead byte 0xED, UTF-16BE comparison otherwise.

## Why

These are the PHP traps of plan §6 (1, 2, 3, 4, 6, 8). Each has a test that fails on the naive
version; the P1 mutation run (lost tombstones, case-folded or byte order, `json_encode` numbers,
plain key sort, int keys, `[]` for `{}`, `==` elements, op-id reuse) is caught by the suite.

## Faithful oddities kept from the TypeScript core

- A write the schema refuses for its *kind* (`inc` on an `lww` field) fails after the clock tick
  and sequence number are consumed, as in `writer.ts` (`#base` only checks that the field exists).
  Unknown fields and record types fail before. Op ids are never reused either way.
- `loadSnapshot` validates the snapshot's fields before changing anything (TypeScript drops the
  record's ops first); the result for valid snapshots is identical.
