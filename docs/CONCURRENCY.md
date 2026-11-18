# Concurrency (REQ-RUNTIME-003)

How `nowo-tech/transaction-doctrine` behaves when the same data is written at the same time, and what it gives the
bundles that depend on it. The normative rules are nowo-tech REQ-RUNTIME-003; how a bundle uses this package to meet
them is in [Usage](USAGE.md#how-bundles-comply-with-req-runtime-003-using-transaction-doctrine).

## Table of contents

- [Write paths](#write-paths)
- [Conflict classification](#conflict-classification)
- [Conflict codes and answers](#conflict-codes-and-answers)
- [Nested transactions and replays](#nested-transactions-and-replays)
- [Host options](#host-options)
- [Tests](#tests)
- [Accepted residual risk](#accepted-residual-risk)

## Write paths

The package owns no table: it writes only the host's or a bundle's data, inside the work it is given.

| Path | Transaction | Concurrency control | Conflict answer |
|------|-------------|---------------------|-----------------|
| `DoctrineTransactionRunner::run()` | One transaction (or a savepoint inside the caller's) | The caller's | Transient failure rethrown as the contract's `TransientStorageFailure`; nesting level repaired |
| `WriteUnit::run()` (programmatic, `#[AsWriteUnit]`, `controller_routes`, `WriteUnitMiddleware`) | The unit's runners; never replayed inside a caller's transaction | Replays a deadlock, lock wait timeout or serialization failure (configurable kinds) with full-jitter backoff | `ConcurrencyConflict` after the last attempt, or at once with `committed: true` when the failed attempt had already committed |
| `ConcurrencyConflictResponder` | None | None | 409 (or 503) + `CONFLICT_001` + `Retry-After`, or `CONFLICT_002` without `Retry-After` |
| `WorkerConnectionHygiene` | Rolls back leftover levels | None | Closed managers reset, dead connections closed between requests and messages |

The only `flush()` in `src/` is the runner's own, inside its transaction (`forEntityManager()`, the unit the caller
asked it to run). Entity managers are cleared or reset only after a failure the runner or a write unit owns: a closed
manager is reset, and a manager whose unit of work the failed run touched is cleared (see [Usage](USAGE.md#behaviour-after-a-failure)).
Between two attempts a write unit clears the open managers, which `ResetBetweenAttemptsInterface` makes safe for
request memos.

## Conflict classification

`TransientFailureClassifier` walks the previous chain and reads driver codes only:

| Server | Code | Kind | Conflict code |
|--------|------|------|---------------|
| MySQL / MariaDB | 1213 (deadlock) | `Deadlock` | `CONFLICT_001` |
| MySQL / MariaDB | 1205 (lock wait timeout) | `LockWaitTimeout` | `CONFLICT_001` |
| MariaDB | 1020 (record changed, snapshot isolation) | `SerializationFailure` | `CONFLICT_001` |
| PostgreSQL | 40P01 (deadlock) | `Deadlock` | `CONFLICT_001` |
| PostgreSQL | 55P03 (lock not available: `lock_timeout`, `NOWAIT`) | `LockWaitTimeout` | `CONFLICT_001` |
| PostgreSQL | 40001 (serialization failure) | `SerializationFailure` | `CONFLICT_001` |
| MySQL / MariaDB | 1305 `SAVEPOINT … does not exist`, with a deadlock in the chain | `Deadlock` | `CONFLICT_001` |
| MySQL / MariaDB, PostgreSQL | 1305 `SAVEPOINT … does not exist` / 3B001, with no transient failure in the chain | not transient; `isCommitOutcomeUnknown()` | `CONFLICT_002` |

Never mapped, wherever they sit in the chain:

- unique and other constraint violations (SQLSTATE class 23, `ConstraintViolationException`);
- lost connections (SQLSTATE class 08, MySQL / MariaDB 2006, 2013 and 4031, `ConnectionException` / `ConnectionLost`):
  the outcome of a commit is unknown to the driver, and replaying could repeat it;
- a 1305 about a missing `FUNCTION` or `PROCEDURE`;
- an application exception that happens to use a vendor code.

## Conflict codes and answers

`Nowo\TransactionDoctrine\ConflictCode`:

- `CONFLICT_001` (`ConflictCode::RetrySafe`, constant `RETRY_SAFE`): nothing was committed, the client may retry after
  `Retry-After`.
- `CONFLICT_002` (`ConflictCode::Committed`, constant `COMMITTED`): part of the request may already be committed;
  read again, do not repeat. No `Retry-After`.

`ConflictCode::forFailure()` reads the first `ConcurrencyConflict` of the chain (`committed`), else the lost-savepoint
rule. The answers:

```json
{"error": "concurrency_conflict", "code": "CONFLICT_001", "message": "Your change was not saved because the same data was being changed at the same time. Nothing was lost: try again."}
```

```json
{"error": "concurrency_conflict_partial", "code": "CONFLICT_002", "message": "Part of your change may have been saved before the same data was changed at the same time. Reload before trying again."}
```

```html
<main data-testid="concurrency-conflict" data-conflict-code="CONFLICT_001">…Try again…</main>
```

With UiKit the page extends UiKit's error page and the code sits on
`<span hidden data-testid="concurrency-conflict" data-conflict-code="…">`. A `ConflictResponseFormatterInterface`
gets the code as `$context->code`. Every answer gets the policy's status (409 by default), `Cache-Control: no-store,
private`, and `Retry-After` only with `CONFLICT_001`.

By default the responder answers `ConcurrencyConflict` (and anything implementing `LockConflictExceptionInterface`) and
whatever a tagged `ConflictExceptionMapperInterface` accepts. A raw transient failure or a bare lost savepoint that
reaches the kernel outside a write unit is answered only with `conflict_response.map_transient_failures: true`.

## Nested transactions and replays

- After a failure inside a nested level, `ConnectionRepairer` rolls the nesting level back to where it started; when
  `ROLLBACK TO SAVEPOINT` fails (the server dropped the savepoints after a deadlock) the connection is closed, never
  left poisoned. A failed rollback is never reported as success.
- Between two attempts `DoctrineStateRepairer::discardUnitOfWork()` rolls back leftover levels, resets closed managers
  and clears open ones; then the `ResetBetweenAttemptsInterface` services and `WriteUnitRetryEvent` listeners run.
- When one of them throws, or leaves a transaction or pending changes, the replay is abandoned. Since 1.5.0 (audit
  TA-6) what it left is discarded in both cases, so the connection is back at nesting level 0 before
  `ConcurrencyConflict` is thrown.
- With `commit_detection` (default) a failed attempt that already committed is never replayed: `ConcurrencyConflict`
  with `committed: true`, answered `CONFLICT_002`.

## Host options

| Option | Default | Effect |
|--------|---------|--------|
| `conflict_response.status` | `409` | `503` per host (owner decision 2026-11-17: one status across the platform, configurable) |
| `conflict_response.retry_after` | `1` | Seconds sent with `CONFLICT_001` |
| `conflict_response.json_error` | `concurrency_conflict` | The JSON `error` key (`_partial` suffix with `CONFLICT_002`); `code` is fixed |
| `conflict_response.map_transient_failures` | `false` | Also answer raw transient failures and bare lost savepoints reaching the kernel |
| `commit_detection` | `true` | Feeds `CommitLedger`, which turns a committed-then-conflict attempt into `CONFLICT_002` |
| `replay_on`, `max_attempts`, `backoff` | `deadlock`, `lock_wait_timeout`, `serialization_failure` (1.5.0); see [Configuration](CONFIGURATION.md) | Which kinds a write unit replays, and how; an explicit `replay_on` list replaces the default |

See [Configuration](CONFIGURATION.md) for the full tree.

## Tests

The `Database` suite runs on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, on DBAL 4 + ORM 3, DBAL 3 + ORM 3 and
DBAL 3 + ORM 2 (CI job "Real databases"; no test may be skipped), with two connections (the second in its own
process):

- real deadlocks, lock wait timeouts and serialization failures, replayed or answered `CONFLICT_001` + `Retry-After`;
- a real serialization failure inside a write unit (PostgreSQL 40001, MariaDB 1020) replayed by default, then answered
  409 `CONFLICT_001` once the attempts are exhausted; an explicit `replay_on` without it rethrows it unchanged;
- a committed-then-conflict unit, not replayed (`committed: true`, `CONFLICT_002`);
- a real lost savepoint (`ROLLBACK TO SAVEPOINT` unknown: 1305 / 3B001) answered `CONFLICT_002` with no
  `Retry-After`; a real 1305 for a missing function, a real unique violation and a real killed session not mapped;
- nested deadlocks and failed savepoint rollbacks repaired to nesting level 0;
- replays with request memos reset between attempts, and resetters or listeners that open a transaction and throw
  (TA-6) leaving level 0 and nothing committed.

## Accepted residual risk

- `CONFLICT_002` from a bare lost savepoint is conservative: the savepoint may have vanished for a reason that
  committed nothing (for example the server rolled back the transaction for a reason the chain does not carry). The
  client is told to read again, never to repeat, which is safe either way.
- Commit detection sees commits on the connections wrapped by `CommitTrackingMiddleware`; a commit made through
  another connection (a second DBAL connection without the middleware, an HTTP call) is invisible to it, so a unit
  must commit at most once or be idempotent as a whole.
