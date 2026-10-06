# ADR-P08: touchDevice commits with synchronous_commit = off

**Status:** accepted (P2)

## Decision

The device upsert run on every sync request (`touchDevice`, same SQL as TypeScript) runs in its own
transaction with `set local synchronous_commit = off`. Everything else commits synchronously.

## Why

Concurrent requests from one device serialise on its `devices` row, and each commit waited for a WAL
flush: a burst of 150 requests took ~380 ms through `php -S` and the rate-limit conformance test was
flaky (see ADR-P07). With asynchronous commit, ~210 ms and stable. Nothing is lost that matters: a
crash can forget the last `last_seen` update or a device registration from the last few
milliseconds; the device is registered again on its next request, and any later synchronous commit
(a push) flushes the WAL before it, so an op is never durable without its device row.
