# Feature Specification: Conflict codes in conflict answers (REQ-RUNTIME-003)

**Feature Branch**: `feat/conflict-codes`  
**Status**: Active (next minor, 1.5.0)  
**Source**: Owner, 2026-11-18: REQ-RUNTIME-003 added to the bundle requirements; concurrency wave phase 1. Most bundles
inherit their conflict answer from this package, which answered `{"error":"concurrency_conflict"}` with no code
(blocking rule 6 everywhere). Folds in audit TA-6.

**Package**: `nowo-tech/transaction-doctrine`

---

## Summary

Every conflict answer carries `CONFLICT_001` (nothing committed, retry is safe, `Retry-After`) or `CONFLICT_002`
(part of the unit committed or commit outcome unknown, read again, no `Retry-After`), additively: the JSON `error` key,
the status (409 by default, 503 per host) and the HTML texts are unchanged.

## Requirements

| ID | Requirement |
|----|-------------|
| FR-001 | `Nowo\TransactionDoctrine\ConflictCode` backed enum (`RetrySafe` = `CONFLICT_001`, `Committed` = `CONFLICT_002`) with public constants and `forFailure(Throwable)`. |
| FR-002 | JSON answers: `{"error": <json_error>[_partial], "code": "CONFLICT_00n", "message": …}`. |
| FR-003 | HTML answers (plain, Twig, UiKit): `data-conflict-code` on the `data-testid="concurrency-conflict"` element; the template gets `conflict_code` and derives it from `partial` when missing. |
| FR-004 | `ConflictResponseContext::$code` (optional constructor argument, derived from `committed`), so formatters receive it. |
| FR-005 | `Retry-After` only with `CONFLICT_001`. Default status stays 409. |
| FR-006 | Classifier: MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03 transient; a lost savepoint (1305 naming a SAVEPOINT, 3B001) with no transient failure in the chain is commit-outcome-unknown (`CONFLICT_002`). Unique / constraint violations and lost connections are never mapped; a 1305 about a missing function is not a lost savepoint. |
| FR-007 | The opt-in `TransientFailureConflictMapper` maps commit-outcome-unknown failures too. |
| FR-008 | Audit TA-6: when a resetter or retry listener throws, `prepareReplay` discards the unit of work (leftover levels, pending changes) before `abandonReplay`. |

## Acceptance

- Unit: JSON / HTML / plain / UiKit / formatter shapes for both codes; context derivation; classifier and mapper cases
  per code; template without `conflict_code`.
- Real databases (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; DBAL 3 / 4): lock wait timeout, deadlock and serialization
  failure answer `CONFLICT_001` + `Retry-After`; a real lost savepoint answers `CONFLICT_002` without it; a real missing
  function (1305), unique violation and killed session are not mapped; a resetter or listener that opens a
  transaction and throws leaves nesting level 0 and nothing committed.
