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
| `DoctrineTransactionRunner::forHostEntityManager()` (1.6.0) | As `run()` above | The caller's | As `run()`; an open host manager is never closed, cleared or reset, a closed one is replaced |
| `ConcurrencyConflictResponder` | None | None | 409 (or 503) + `X-Conflict-Code` + `CONFLICT_001` + `Retry-After`, or `CONFLICT_002` without `Retry-After` (also after a commit earlier in the request, 1.6.0) |
| `RequestCommitWatermark` (1.6.0, with `commit_detection`) | None | Reads the `CommitLedger` count at `kernel.request` | Turns a later conflict of the request into `CONFLICT_002` when the count moved |
| `WorkerConnectionHygiene` | Rolls back leftover levels | None | Closed managers reset, dead connections closed between requests and messages |

The only `flush()` in `src/` is the runner's own, inside its transaction (`forEntityManager()` and
`forHostEntityManager()`, the unit the caller asked it to run). Entity managers are cleared or reset only after a
failure the runner or a write unit owns: with `forEntityManager()` (bundle-owned managers) a closed manager is reset,
and a manager whose unit of work the failed run touched is cleared (see [Usage](USAGE.md#behaviour-after-a-failure)).
With `forHostEntityManager()` (the host's manager, REQ-RUNTIME-003 rule 2) an open manager is never closed, cleared or
reset: only what the failed run brought in is detached, and a closed manager is replaced through the registry (see
[Usage](USAGE.md#the-hosts-entity-manager-forhostentitymanager-160)).
Between two attempts a write unit clears the open managers, which `ResetBetweenAttemptsInterface` makes safe for
request memos. That includes the host's default manager (REQ-RUNTIME-003 rule 2): a bundle whose unit writes only
through its own manager scopes the repair with `WriteUnit::forManagers('my_bundle')` (1.7, audit UD2-L-2), so the
host's manager keeps its entities (see [Nested transactions and replays](#nested-transactions-and-replays)).

## Conflict classification

`TransientFailureClassifier` walks the previous chain and reads driver codes only. Its static finders (1.7, audits
TA42-3 / UA2-5) also look inside Messenger wrappers, through the bounded walk `ConflictCode` uses:
`findConflict($failure)` (a lock conflict or a transient failure), `findLockConflict()` (what the responder answers
by default) and `findTransientFailure()` (what `map_transient_failures` maps). A deadlock in the second handler of a
synchronous `HandlerFailedException` is therefore a 409, not a 500. Bundles with their own conflict subscriber call
them instead of walking `getPrevious()`.

| Server | Code | Kind | Conflict code |
|--------|------|------|---------------|
| MySQL / MariaDB | 1213 (deadlock) | `Deadlock` | `CONFLICT_001` |
| MySQL / MariaDB | 1205 (lock wait timeout) | `LockWaitTimeout` | `CONFLICT_001` |
| MariaDB | 1020 (record changed, snapshot isolation) | `SerializationFailure` | `CONFLICT_001` |
| PostgreSQL | 40P01 (deadlock) | `Deadlock` | `CONFLICT_001` |
| PostgreSQL | 55P03 (lock not available: `lock_timeout`, `NOWAIT`) | `LockWaitTimeout` | `CONFLICT_001` |
| PostgreSQL | 40001 (serialization failure) | `SerializationFailure` | `CONFLICT_001` |
| MySQL / MariaDB | 1305 about a `SAVEPOINT` (any `lc_messages` language), with a deadlock in the chain | `Deadlock` | `CONFLICT_001` |
| MySQL / MariaDB, PostgreSQL | 1305 about a `SAVEPOINT` / 3B001, with no transient failure in the chain | not transient; `isCommitOutcomeUnknown()` | `CONFLICT_002` |

Never mapped, wherever they sit in the chain:

- unique and other constraint violations (SQLSTATE class 23, `ConstraintViolationException`);
- lost connections (SQLSTATE class 08, MySQL / MariaDB 2006, 2013 and 4031, `ConnectionException` / `ConnectionLost`):
  the outcome of a commit is unknown to the driver, and replaying could repeat it;
- a 1305 about a missing `FUNCTION` or `PROCEDURE` (the server never translates the object type, so the classifier
  matches the `SAVEPOINT` keyword where the server puts it, in any `lc_messages` language);
- an application exception that happens to use a vendor code.

## Conflict codes and answers

`Nowo\TransactionDoctrine\ConflictCode`:

- `CONFLICT_001` (`ConflictCode::RetrySafe`, constant `RETRY_SAFE`): nothing was committed, the client may retry after
  `Retry-After`.
- `CONFLICT_002` (`ConflictCode::Committed`, constant `COMMITTED`): part of the request may already be committed;
  read again, do not repeat. No `Retry-After`.

`ConflictCode::forFailure()` answers `CONFLICT_002` when anything in the failure proves a commit (1.6.1): a
`ConcurrencyConflict` with `committed: true` anywhere along `getPrevious()` or inside a Messenger wrapper's
`getWrappedExceptions()`, a `HandlerFailedException` whose envelope carries a `HandledStamp`, or any
`DelayedMessageHandlingException` (the Messenger rules only when `symfony/messenger` is installed; at most 256
throwables walked, each once, and since 1.7 a walk cut short by that cap answers `CONFLICT_002`, audit TA42-1).
Otherwise it reads the first `ConcurrencyConflict` of the chain (`committed`), else the lost-savepoint rule (since 1.7
also applied to the chain of each wrapped exception). `ConflictCode::forRequest($request, $failure)` (1.6.0) adds the request-level rule: `CONFLICT_002` when anything
was committed since the request's `CommitMark`. The mark is taken by `RequestCommitWatermark` at `kernel.request`
(priority 4096) on the main request when `commit_detection` is on; sub-requests carry the main request's mark. The
responder uses `forRequest()`, so a conflict after a commit earlier in the same request (by the host, another bundle
or an earlier unit) never invites a blind retry. Without commit detection the failure alone decides (1.5 behaviour).
The answers:

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
private`, `X-Conflict-Code: CONFLICT_001|CONFLICT_002` (1.6.0), and `Retry-After` only with `CONFLICT_001`.

`X-Conflict-Code` is the platform convention (audit TH X-2): only `CONFLICT_001` and `CONFLICT_002`, never a domain
code. It carries the code when the body has none (the host's error page). Bundles answering themselves send it with
`ConflictResponsePolicy::headersFor($code)` or `httpExceptionFor($code, …)`. Cross-origin APIs list it in
`Access-Control-Expose-Headers`. Clients key on the `code` body key or on the header, never on the `error` string,
which differs between bundles and is kept only for BC.

By default the responder answers `ConcurrencyConflict` (and anything implementing `LockConflictExceptionInterface`) and
whatever a tagged `ConflictExceptionMapperInterface` accepts. A raw transient failure or a bare lost savepoint that
reaches the kernel outside a write unit is answered only with `conflict_response.map_transient_failures: true`.

## Nested transactions and replays

- After a failure inside a nested level, `ConnectionRepairer` rolls the nesting level back to where it started; when
  `ROLLBACK TO SAVEPOINT` fails (the server dropped the savepoints after a deadlock) the connection is closed, never
  left poisoned. A failed rollback is never reported as success.
- Between two attempts `DoctrineStateRepairer::discardUnitOfWork()` rolls back leftover levels, resets closed managers
  and clears open ones; then the `ResetBetweenAttemptsInterface` services and `WriteUnitRetryEvent` listeners run.
- By default that covers every manager and connection of the registry, the host's default manager included: a replay
  detaches every entity the host loaded before the unit (audit UD2-L-2). A bundle whose unit writes only through its
  own manager scopes it (1.7): `WriteUnit::forManagers('my_bundle')`, or `new WriteUnit(..., managers: ['my_bundle'])`.
  Only those managers are reset or cleared and only their connections rolled back. Name every manager the unit writes
  through. The checks before the first attempt still read every connection and manager (a caller's transaction or
  pending changes anywhere make the unit run once), and a replay that would start inside a transaction or over
  pending changes on any of them is abandoned with `ConcurrencyConflict`, never run nested.
- When one of them throws, or leaves a transaction or pending changes, the replay is abandoned. Since 1.5.0 (audit
  TA-6) what it left is discarded in both cases, so the connection is back at nesting level 0 before
  `ConcurrencyConflict` is thrown.
- With `commit_detection` (default) a failed attempt that already committed is never replayed: `ConcurrencyConflict`
  with `committed: true`, answered `CONFLICT_002`. A COMMIT the server refuses is not counted, and it ends the tracked
  transaction, so the autocommit writes after it are counted (audit TA2-1, fixed in 1.5.0).

## Host options

| Option | Default | Effect |
|--------|---------|--------|
| `conflict_response.status` | `409` | `503` per host (owner decision 2026-11-17: one status across the platform, configurable) |
| `conflict_response.retry_after` | `1` | Seconds sent with `CONFLICT_001` |
| `conflict_response.json_error` | `concurrency_conflict` | The JSON `error` key (`_partial` suffix with `CONFLICT_002`); `code` is fixed |
| `conflict_response.map_transient_failures` | `false` | Also answer raw transient failures and bare lost savepoints reaching the kernel |
| `commit_detection` | `true` | Feeds `CommitLedger`, which turns a committed-then-conflict attempt into `CONFLICT_002`; also takes the request commit watermark (1.6.0), so a conflict after a commit earlier in the request answers `CONFLICT_002` |
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
- through a real `HttpKernel` with the watermark and the responder (1.6.0): a commit then a conflict in a unit that
  committed nothing answers `CONFLICT_002` with no `Retry-After`; no commit answers `CONFLICT_001`; a commit before
  the mark (the previous request of the same kernel, a listener above priority 4096) answers `CONFLICT_001`; a
  sub-request reads the main request's mark; every answer carries `X-Conflict-Code`;
- `forHostEntityManager()` (1.6.0): a storage failure and a flushed refusal leave the host's open manager in place
  (same instance, not cleared, its entity and pending edit kept, the run's entities detached), and a manager the ORM
  closed in a real lock wait timeout is replaced; a detach never cascades into a host entity, a rolled-back child
  leaves the host's collection, a to-one gets its original value back, and the host's own changes stay with a
  notice (audit TA3; the same scenarios also run on SQLite in the unit suite);
- reads (`WITH`, `VALUES`, `TABLE` included) never move the request mark; a data-modifying CTE does;
- a host manager with pending changes (an edit, a persist, a remove) is refused before the run, and flushed intact
  afterwards; when `computeChangeSets()` itself throws (a `prePersist` listener on a cascade-persisted child) the
  refusal keeps that exception as previous (1.6.1);
- a real lock wait timeout from a second session inside a Messenger `HandlerFailedException` answers `CONFLICT_001`,
  and `CONFLICT_002` without `Retry-After` once another handler succeeded (`HandledStamp`) or inside a
  `DelayedMessageHandlingException` (1.6.1);
- the same timeout, a write unit's `ConcurrencyConflict` and a real lost savepoint in the SECOND wrapped exception of
  a `HandlerFailedException` are detected (409, not 500; the lost savepoint `CONFLICT_002`), and a real unique
  violation there is not (1.7, audits TA42-3 / UA2-5);
- a write unit scoped to a bundle's own manager on its own connection replays a real lock wait timeout without
  clearing the host's manager (its entity still managed), while the unscoped unit clears it (1.7, audit UD2-L-2);
- with commit tracking on, a real COMMIT returns the driver's `true` on DBAL 3 (`void` on DBAL 4), is recorded, and a
  second connection sees the row (1.7, audit UE-CF-1);
- PostgreSQL COMMITs the server refuses, on every leg (DBAL 3 included, audit TA3-5): a SERIALIZABLE write skew
  (40001) becomes `TransientStorageFailure` and a write unit replays it; a deferred unique violation (23505) is
  rethrown unchanged; the connection is back at level 0 each time;
- a real lost savepoint (`ROLLBACK TO SAVEPOINT` unknown: 1305 / 3B001) answered `CONFLICT_002` with no
  `Retry-After`, also with localized server messages; a real 1305 for a missing function, a real unique violation and a real killed session not mapped;
- nested deadlocks and failed savepoint rollbacks repaired to nesting level 0;
- replays with request memos reset between attempts, and resetters or listeners that open a transaction and throw
  (TA-6) leaving level 0 and nothing committed.

## Accepted residual risk

- `CONFLICT_002` from a bare lost savepoint is conservative: the savepoint may have vanished for a reason that
  committed nothing (for example the server rolled back the transaction for a reason the chain does not carry). The
  client is told to read again, never to repeat, which is safe either way.
- The request watermark is conservative too: any tracked commit after the mark counts, including one unrelated to
  the failed work (a Doctrine Messenger transport send, an audit row). The client is told to read again; it is never
  invited to repeat work that may be committed.
- The Messenger wrapper rules (1.6.1, audit TA4-2) over-approximate on purpose, as DDRB 3.2.0 does: a
  `HandlerFailedException` with a `HandledStamp` answers `CONFLICT_002` even when the handler that succeeded wrote
  nothing, or wrote inside an outer transaction that then rolled back; any `DelayedMessageHandlingException` answers
  `CONFLICT_002` even when the outer dispatch ran under a transaction that rolled back, so nothing committed. The
  failure does not say whether that work reached the database, so the client is told to read again; it is never
  invited to repeat work that may be committed.
- `forHostEntityManager()` refuses a host manager with pending changes before the run (`LogicException`), so the
  host's own work never rides in a transaction that rolls back. That costs one `computeChangeSets()` per run.
- `forHostEntityManager()` forgets only what the run brought in (detached without cascading into the caller's
  entities, removed from their collections, to-ones restored): any other change the operation made to the caller's
  entities stays (a changed field, a removed element, a value flushed and rolled back), with a notice in the log. The
  caller decides whether to `refresh()` or `clear()`.
- The ledger over-counts on purpose: outside a transaction any statement that is not a plain read (`SELECT`, `SHOW`,
  `SET`, `EXPLAIN`, `DESCRIBE`, `PRAGMA`, `WITH`, `VALUES`, `TABLE`) counts as a commit, and so does a `WITH` that
  names `INSERT`, `UPDATE`, `DELETE` or `MERGE` anywhere (a data-modifying CTE, or only an identifier spelled that
  way). An over-count turns a `CONFLICT_001` into `CONFLICT_002` (read again instead of retry); it never invites a
  retry of committed work.
- Detection walks at most 256 throwables (1.7: inside Messenger wrappers too). A conflict beyond the cap is not
  detected (the failure stays a 500), while the conflict code of a detected one over-approximates to `CONFLICT_002`.
  Only pathological wrappers carry that many throwables.
- Commit detection sees commits on the connections wrapped by `CommitTrackingMiddleware`; a commit made through
  another connection (a second DBAL connection without the middleware, an HTTP call) is invisible to it, so a unit
  must commit at most once or be idempotent as a whole.
