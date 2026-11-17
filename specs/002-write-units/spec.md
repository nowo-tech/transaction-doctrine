# Feature Specification: Write units and the 409 lock-conflict answer

**Feature Branch**: `feat/kit79-write-units`  
**Status**: Active (1.3.0)  
**Source**: Beacon re-audit 2026-11-17, row KIT-79 (Beacon spec 239 `src/Shared/Concurrency/**`); owner platform decision
"one lock-conflict status: 409 + Retry-After" (OTHER REQ-SCALE-002); TaskBoard API small ask C2.

**Package**: `nowo-tech/transaction-doctrine`  
**Code inventory**: [`../001-baseline/code-inventory.md`](../001-baseline/code-inventory.md)

---

## Summary

Move the generic parts of Beacon's write units into the package so every bundle and host gets them by configuration:
replay a deadlock or lock wait timeout at the outermost unit (programmatic API, `#[AsWriteUnit]` controllers,
Messenger middleware), answer the final conflict with one platform status (409 + `Retry-After` by default, 503 kept by
configuration) in JSON or a branded HTML page, keep connections and entity managers healthy in FrankenPHP worker mode,
and let hosts and bundles map their own exceptions without route-name matching.

---

## User Scenarios

### US-01 — Replay a lock conflict (P1)

**Given** a unit of work that meets a deadlock or a lock wait timeout, **When** it runs as a write unit outside any
open transaction, **Then** it is replayed up to `max_attempts` times with a full-jitter pause, the entity managers
cleared or reset and leftover levels rolled back between attempts; inside an open transaction it runs once.

### US-02 — One lock-conflict answer (P1)

**Given** a write unit whose last attempt still conflicts, **When** the failure reaches the kernel, **Then** the
response is `conflict_response.status` (409 by default, or 503) with `Retry-After`, as `{"error", "message"}` JSON for
JSON / XHR / API requests or a branded page with *Try again* (UiKit's error page when installed) otherwise.

### US-03 — Bundles and hosts map their own failures (P1)

**Given** an exception a bundle or host owns, **When** it implements `LockConflictExceptionInterface` or a tagged
`ConflictExceptionMapperInterface` accepts it, **Then** it gets the same answer; a tagged
`ConflictResponseFormatterInterface` shapes the body of its own requests (TaskBoard API `{err, ECODE}`) and the status
stays the policy's. Bundles with their own JSON shapes read the status from `ConflictResponsePolicy`.

### US-04 — Worker connection hygiene (P1)

**Given** FrankenPHP worker mode with kernel reset off, **When** a request or consumed message fails with a DBAL / ORM
error, **Then** closed managers are reset, leftover levels rolled back and connected connections pinged before the next
unit; closed managers are reset at every main request.

---

## Requirements

### Write units

- **FR-WU-001**: `WriteUnit` / `WriteUnitInterface` — `run(callable(int, ?Throwable))`; replay kinds from
  `TransientFailureClassifier`; a Messenger wrapper only when every wrapped exception is a replayable conflict and no
  `HandledStamp` (audit SL-M4); outermost unit only; a `ConcurrencyConflict` anywhere in the failure (chain or wrapped)
  never replayed again (SL-L1); run once when an open entity manager has pending changes (SL-M2); stateless.
  **A unit must commit at most once, or be idempotent as a whole.**
- **FR-WU-005**: `CommitLedger` + `Dbal\CommitTrackingMiddleware` — count successful COMMITs and write statements
  outside a transaction, multi-statement strings, `EXPLAIN ANALYZE`, MySQL implicit commits inside a transaction
  (SL-D-L1); a failed attempt that committed is not replayed (`ConcurrencyConflict::$committed`; SL-M1). Such a
  conflict is answered 409 without `Retry-After`, code `<json_error>_partial`, "reload before trying again" texts,
  and is unrecoverable for Messenger (SL-D-M1).
- **FR-WU-002**: `WriteUnitPolicy` — attempts, full-jitter bounds, replay kinds, Retry-After hint; validated.
- **FR-WU-003**: `DoctrineStateRepairer` — reset closed managers; discard a unit of work (rollback through
  `ConnectionRepairer`, clear / reset); after a database failure ping connected connections only.
- **FR-WU-004**: `ConcurrencyConflict` (a contract `TransientStorageFailure`), `LockConflictExceptionInterface`,
  `#[AsWriteUnit]`.

### Symfony bundle

- **FR-SF-001**: `NowoTransactionDoctrineBundle` — config tree `nowo_transaction_doctrine` (`max_attempts`, `backoff`,
  `replay_on`, `controller_attribute`, `controller_routes`, `messenger_middleware`, `worker_hygiene`,
  `conflict_response.{enabled,status,retry_after,template,json_error,json_path_prefixes,map_transient_failures}`).
- **FR-SF-002**: `WriteUnitControllerListener` — attribute and listed routes (exact names, explicit `*` wildcard;
  SL-L3); on a replay only Doctrine entity arguments reloaded by identifier, other arguments (request payload DTOs)
  kept (SL-M3); entity user reloaded, fail closed when gone (SL-I1).
- **FR-SF-003**: `WriteUnitMiddleware` / `UnreplayableDispatch` — consumed messages only; no in-process replay after a
  partial success or a delayed-dispatch failure; final conflict inside `HandlerFailedException`.
- **FR-SF-004**: `ConcurrencyConflictResponder` — 409 / 503 + `Retry-After` + `no-store`; JSON / HTML / formatter;
  plain page without Twig or on a template failure; constant log message.
- **FR-SF-005**: `ConflictResponsePolicy`, `ConflictResponseContext`, `ConflictExceptionMapperInterface`,
  `ConflictResponseFormatterInterface`, `TransientFailureConflictMapper`.
- **FR-SF-006**: `WorkerConnectionHygiene` — request / exception / terminate / message-failed listeners; the mark on
  the request.
- **FR-SF-007**: `templates/conflict.html.twig`, `templates/conflict_ui_kit.html.twig`, translations en / es / fr / de.

---

## Success Criteria

- **SC-001**: every new `src/` file mapped in the code inventory.
- **SC-002**: 100% lines and methods (CI gate); real-database tests replay a real deadlock (MySQL, MariaDB) and lock
  wait timeouts (MySQL, MariaDB, PostgreSQL).
- **SC-003**: PHPStan level 8 with the FrankenPHP worker ruleset and Igor pass; repeated-run tests show no state or
  memory growth.
- **SC-004**: audit SL probes P1 (two-commit unit), P2 (shared-manager pending changes), P3 (wrapped inner conflict),
  P4 (`#[MapRequestPayload]` replay) and P5 (mixed handler failure) are regression tests.

---

## Explicit non-goals

- A runtime dependency on Symfony (the bundle is optional; Symfony packages are dev requirements and suggestions).
- Changing ChatKit, Wiki or TimeTrack here (each bumps in its own minor; see `docs/UPGRADING.md`).
- Beacon's self-report to its own monitoring (a host concern; the responder logs).
