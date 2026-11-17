# Feature Specification: Transaction Doctrine baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Status**: Active  

**Package**: `nowo-tech/transaction-doctrine`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

Plain Doctrine default implementation of `nowo-tech/transaction-contract`: `DoctrineTransactionRunner` runs an
operation in one transaction with **one attempt and no retry**, and after any failure leaves neither a poisoned
connection nor pending entity-manager changes. Transient storage failures become `TransientStorageFailure`. No Symfony.

---

## User Scenarios

### US-01 — Run a unit of work (P1)

**Given** a DBAL connection or an entity manager, **When** a bundle calls `run($operation)`, **Then** the runner
begins, runs, flushes (entity-manager variant) and commits, returning the operation's result.

### US-02 — Failure leaves a clean connection (P1)

**Given** an operation that fails, **When** the runner handles it, **Then** it rolls back to the entry level and closes
the connection only when that rollback fails (lost savepoints after a nested deadlock); DBAL 3 emulated nesting is
rolled back level by level.

### US-03 — Failure leaves no pending changes (P1)

**Given** an entity-manager run that fails, **When** the failure is a storage failure the manager is closed and reset
(or cleared without a registry); on a domain failure it is cleared, or only the entities that entered the identity map
during the run are detached (refusal), as documented in the README.

### US-04 — Transient classification (P1)

**Given** a storage error, **When** it is a deadlock, lock wait, serialization failure or DBAL `RetryableException`,
**Then** it is rethrown as `TransientStorageFailure` with the original as `previous`; anything else is rethrown
unchanged.

---

## Requirements

### Runner

- **FR-RUN-001**: `DoctrineTransactionRunner` — `forConnection()`, `forEntityManager()`, `run()`; readonly, no state
  across calls.
- **FR-RUN-002**: `RunObserver` (internal) — per-run `postFlush` / `postLoad` listener, attached for one run and
  detached in `finally`.

### Repair

- **FR-REP-001**: `ConnectionRepairer` — `afterFailure()`, `betweenRequests()`; roll back to a level, close only when
  the rollback fails, clear DBAL 3 rollback-only state.

### Classification

- **FR-CLS-001**: `TransientFailureClassifier` — vendor codes 1213, 1205, MariaDB 1020, SQLSTATE 40001 / 40P01 /
  55P03, DBAL `RetryableException`; 1305 only through the deadlock it carries.
- **FR-CLS-002**: `TransientFailureKind` — enum of the transient kinds.

### Observability

- **FR-OBS-001**: `SafeLog` (internal) — optional PSR-3 logging that never breaks the failure path: a throwing logger
  is ignored and contexts carry class names, codes and counters only, never SQL, parameters or messages.

---

## Success Criteria

- **SC-001**: **5/5** `src/` files mapped.
- **SC-002**: PHPUnit covers 100% of lines and methods (CI gate); the real-database suite runs on MySQL 8.4,
  MariaDB 11.4 and PostgreSQL 16 for DBAL 4 + ORM 3, DBAL 3 + ORM 3 and DBAL 3 + ORM 2.

---

## Explicit non-goals

- Retrying (the retrying Symfony adapter is DoctrineDeadlockRetryBundle 3.0).
- A runtime Symfony dependency (the 1.3.0 bundle, spec 002, is optional).

---

## Validation

PHPUnit (unit + real-database suites, coverage gate), PHPStan level 8 with FrankenPHP rulesets, Igor, inventory audit.
