# Feature Specification: Reset hook between write-unit attempts

**Feature Branch**: `feat/reset-between-attempts`  
**Status**: Active (next minor, 1.4.0)  
**Source**: SYMFONY-BEACON spec 241 T020 review, 2026-11-18. Beacon's per-request memos (LeaveDirectory, LeaveAccess,
OrganizationContext, RoomChannelAccess) keep entities; a write unit clears or resets the entity managers between
attempts, so a replay used detached entities (`ORMInvalidArgumentException`, a 500 instead of the 409; or silent
no-ops).

**Package**: `nowo-tech/transaction-doctrine`

---

## Summary

Give hosts and bundles a hook to empty request memos before a write unit replays its work: a tag interface
`ResetBetweenAttemptsInterface` (main API, autoconfigured) and a PSR-14 `WriteUnitRetryEvent` (bonus, for observers
and state that cannot be tagged). One implementation in `WriteUnit::run()` covers every entry point.

## Requirements

| ID | Requirement |
|----|-------------|
| FR-001 | Services implementing `ResetBetweenAttemptsInterface` are autoconfigured with the tag `nowo_transaction_doctrine.reset_between_attempts` and injected into the write unit as a tagged iterator (priority order, then registration order). |
| FR-002 | Before each replay, after the failed attempt's entity managers were cleared or reset (and after the pause), every resetter is called in order, then `WriteUnitRetryEvent` (attempt about to run, the failure, its kind, the unit name) is dispatched on `event_dispatcher` when present. |
| FR-003 | Neither runs before the first attempt, after the last failure, after a failure that is not replayed, or when the unit runs once (inside a transaction, pending changes, committed attempt). |
| FR-004 | An exception from a resetter or a retry listener abandons the replay: the unit gives up with `ConcurrencyConflict` (attempts = the failed attempt, previous = the attempt's failure, `resetFailure` = the reset exception, `Retry-After` kept), logged as a warning. The Messenger middleware wraps it as any other conflict. |
| FR-005 | Unit name: the route name (`_route`) for `#[AsWriteUnit]` and `controller_routes`, the message class for the Messenger middleware, the optional second argument of `WriteUnit::run()` otherwise. `WriteUnitInterface::run()` keeps its 1.x signature. |
| FR-006 | FrankenPHP worker mode with kernel reset off: the write unit keeps no state of a run; the tagged iterator is read on each replay. |

## Acceptance

- Unit: order (clear → resetters → event → attempt), never on attempt 1 or after the final failure, failure abandons
  the replay with the chained exception, kernel wiring with `#[AsTaggedItem]` priority, 2 000 replays keep no memory.
- Real databases (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; DBAL 3 / 4, ORM 2 / 3 in CI): for `#[AsWriteUnit]`,
  `controller_routes` and the Messenger middleware, a memo holding an entity is reset after a real lock wait timeout
  and the replay commits with managed entities; without the reset the replay fails with `ORMInvalidArgumentException`.
