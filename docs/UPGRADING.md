# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
- [To 1.7.2](#to-172)
- [To 1.7.1](#to-171)
- [To 1.7.1](#to-171-1)
- [To 1.7.0](#to-170)
- [To 1.6.1](#to-161)
- [To 1.6.0](#to-160)
  - [Request commit watermark](#request-commit-watermark)
  - [X-Conflict-Code header](#x-conflict-code-header)
  - [forHostEntityManager() for the host's entity manager](#forhostentitymanager-for-the-hosts-entity-manager)
  - [DBAL 3: SQLSTATE codes in driver errors](#dbal-3-sqlstate-codes-in-driver-errors)
- [To 1.5.0](#to-150)
  - [Conflict codes in conflict answers](#conflict-codes-in-conflict-answers)
- [To 1.4.0](#to-140)
  - [Request memos and write-unit replays](#request-memos-and-write-unit-replays)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
  - [To 1.3.0](#to-130-1)
  - [Default lock-conflict status: 409](#default-lock-conflict-status-409)
  - [Migration notes for ChatKit, Wiki and TimeTrack](#migration-notes-for-chatkit-wiki-and-timetrack)
- [To 1.2.0](#to-120)
- [1.0 to 1.1](#10-to-11)
- [1.0.0](#100)

## Unreleased

From **1.8.1**: `composer update nowo-tech/transaction-doctrine`. No configuration or signature change. After a write
unit whose attempt's flush failed, whose manager host code reset, or whose attempt cleared the manager (or detached
every host entity), the host's entities from before the unit are managed again and reloaded, so writes made to them in
the replay are flushed (1.8.1 and earlier: silently ignored). A host entity whose row is gone by then ends the unit with
409 `CONFLICT_001`.

## To 1.8.1

From **1.8.0**:

```bash
composer update nowo-tech/transaction-doctrine
```

From **1.8.0**: `composer update nowo-tech/transaction-doctrine`. No configuration or signature change. A write-unit
replay goes ahead again when the failed attempt's flush closed the manager and host code reset it through the registry
(in place, as DoctrineBundle does), or when the attempt cleared the manager: 1.8.0 answered 409 `CONFLICT_001` there.
The host's entities from before the unit are then detached, as in 1.7.x: load them again in the replay. **Writes to
the old objects are silently ignored**: they are no longer managed, so no flush writes them. The same applies whenever
the attempt's own flush fails, in every version so far: the ORM closes the manager and the write unit's repairer resets
it (audit VQ5-1). 1.8.2 is planned to manage those objects again; until then, re-read what you change from the
manager after a replay.

## To 1.8.0

From **1.7.2**:

```bash
composer update nowo-tech/transaction-doctrine
```

From **1.7.2**: `composer update nowo-tech/transaction-doctrine`. No configuration change. Behaviour change:

- **A write-unit replay keeps the host's entities (audits VD2-4 / VQ).** Before, a replay cleared every open entity
  manager (the host's default one included, unless the unit was scoped with `forManagers()`), so entities loaded
  before `run()` were detached afterwards. Now an open manager that managed entities before the unit is never
  cleared: the same objects stay managed, and they are reloaded from the database when the failed attempt changed,
  flushed, removed or re-hydrated them (one query each; every initialised one after an attempt that flushed). Code
  that re-loaded entities after the unit keeps working; code that kept using the caller's entities after a replay now
  gets managed objects holding the stored state instead of detached ones.
- A replay whose failed attempt detached a host entity, or touched one that cannot be reloaded (its row gone, a
  failing `postLoad` listener), is no longer replayed: it ends with `ConcurrencyConflict` (409,
  `Retry-After`, `CONFLICT_001`) after the rest was restored. Entities the failed attempt loaded are still detached,
  so request memos still need `ResetBetweenAttemptsInterface`.
- Bundle tests that asserted the host manager was cleared after an unscoped replay (`contains()` false) need
  updating.
- `DoctrineTransactionRunner::forHostEntityManager()`: an entity the failed run loaded and removed is now forgotten
  with the run (audit VQ4-1, 1.6.0 to 1.7.2), so the host's next flush no longer deletes its row. Nothing to change.
- `DoctrineStateRepairer::discardUnitOfWork()` now returns a list and takes two optional arguments; a caller that
  ignored the (void) result is unaffected.

## To 1.7.2

From **1.7.1**:

```bash
composer update nowo-tech/transaction-doctrine
```

From **1.7.1**: `composer update nowo-tech/transaction-doctrine`. No configuration or signature change.

- After the repairer closes a connection whose transaction was rollback-only, the next transaction commits again
  (DBAL 4, and DBAL 3 with savepoints; before, `CommitFailedRollbackOnly`). A host that worked around that error after
  a deadlock-and-close can drop the workaround.
- A DBAL 3 test double of `Connection` (whose `getTransactionNestingLevel()` returns `null`) no longer makes the
  runner throw a `TypeError`: the test sees the run's own failure.

## To 1.7.1

From **1.7.0**:

```bash
composer update nowo-tech/transaction-doctrine
```

## To 1.7.1

From **1.7.0**: `composer update nowo-tech/transaction-doctrine`. No configuration or signature change.

- **`DoctrineStateRepairer::discardUnitOfWork()` rolls back every connection (audit UI2-2).** With or without a list
  of managers, it rolls back every open transaction level on every connection of the registry, not only those of the
  named managers. It is meant for the gap between two attempts of a unit that started outside any transaction. A
  bundle calling it from its own repair code must call it only outside a caller's transaction: called while the host
  (or an outer unit) has a transaction open, it rolls that transaction back. This was already the case in 1.6.1 and
  1.7.0; only the documentation is new. There is no runtime guard: the repairer cannot tell a caller's open level from
  the one a failed attempt left.
- **Messenger wrappers in write units (audit UI2-4).** `WriteUnit::conflictKind()` (and so `run()` and the
  Messenger middleware) now reads `getWrappedExceptions()` through the same bounded walk as
  `TransientFailureClassifier::findConflict()`. A custom wrapper whose `getWrappedExceptions()` throws, returns
  something that is not a `Throwable`, or wraps itself no longer replaces the failure or hangs the worker: the failure
  is not replayed and is rethrown unchanged. A failure carrying more than 256 throwables is not replayed either (what
  the walk could not read may hold an inner unit's `ConcurrencyConflict`). Nothing to do for ordinary failures.
- **Worker hygiene (audit UI2-5).** `WorkerConnectionHygiene::isDatabaseFailure()` reads wrappers through the same
  bounded walk. A failure whose walk is truncated (more than 256 throwables, or a `getWrappedExceptions()` that throws)
  now counts as a database failure and triggers the repair (reset closed managers, roll back leftover levels, ping);
  before, a throwing wrapper escaped the listener. Nothing to do.

## To 1.7.0

From **1.6.1**:

```bash
composer update nowo-tech/transaction-doctrine
```

From **1.6.1**: `composer update nowo-tech/transaction-doctrine`. Everything is additive: no configuration change, no
signature change (new optional arguments and new methods only).

- **Bundles with their own entity manager can stop clearing the host's (audit UD2-L-2).** A replay of
  `WriteUnit::run()` clears every open manager by default, the host's default manager included, so every entity the
  host loaded before the unit is detached. A bundle whose write unit writes only through its own manager can scope the
  repair:

  ```php
  $unit = $writeUnit->forManagers('my_bundle'); // or new WriteUnit(..., managers: ['my_bundle'])
  $unit->run(fn (int $attempt) => $this->save($command));
  ```

  Only the named managers are reset or cleared; leftover transaction levels are still rolled back on every
  connection. Name every manager the unit writes through (when one outside the scope is found closed, or a replay is
  abandoned with pending changes outside the scope, every manager is repaired): a replay that would start inside a transaction or over pending changes on any connection or
  manager is abandoned (`ConcurrencyConflict`), never run nested. The checks before the first attempt still read every
  connection and manager. `DoctrineStateRepairer::discardUnitOfWork(['my_bundle'])` does the same for a bundle's own
  repair code; it rolls back every connection, so call it only outside a caller's transaction (see
  [To 1.7.1](#to-171)). Without a scope nothing changes. See [Concurrency](CONCURRENCY.md#nested-transactions-and-replays).
- **Conflicts inside Messenger wrappers (audits TA42-3 / UA2-5).** A `ConcurrencyConflict` (or any
  `LockConflictExceptionInterface`) in a later wrapped exception of a synchronous `HandlerFailedException` now
  answers 409 instead of 500; with `map_transient_failures: true` so does a raw deadlock, lock wait timeout,
  serialization failure or lost savepoint there. A bundle with its own conflict subscriber that walks `getPrevious()`
  can switch to `TransientFailureClassifier::findConflict($failure)` (or `findLockConflict()` /
  `findTransientFailure()`), which returns the conflict or null.
- **More `CONFLICT_002`.** A failure carrying more than 256 throwables (the walk's cap), and a lost savepoint in a
  later wrapped exception, answer `CONFLICT_002` without `Retry-After` instead of `CONFLICT_001`. A test that
  asserted `CONFLICT_001` for one of these needs `CONFLICT_002`.
- **DBAL 3 with commit tracking: `Connection::commit()` returns `true` again (audit UE-CF-1).** It returned `null`
  since commit tracking was added. Nothing to do; code that worked around it (`!== false` instead of `=== true`) keeps
  working. The new class `Nowo\TransactionDoctrine\WriteUnit\Dbal\Dbal3\CommitResultConnection` lives in
  `compat/dbal3/` (PSR-4 autoload entry in `composer.json`) and is loaded only on DBAL 3. DBAL 4 is unchanged.
- **Preloading:** a hand-written preload script that compiles every file of the class map must skip
  `vendor/nowo-tech/transaction-doctrine/compat/dbal3/` on DBAL 4 (its class is incompatible with DBAL 4 and is never
  loaded there). Symfony's generated preload file only lists classes the container uses, so it needs nothing.

## To 1.6.1

From **1.6.0**:

```bash
composer update nowo-tech/transaction-doctrine
```

From **1.6.0**: `composer update nowo-tech/transaction-doctrine`. No configuration or signature change.

- **`ConflictCode::forFailure()` (and the 409 responder) answers `CONFLICT_002`, without `Retry-After`, in more
  cases:** a committed `ConcurrencyConflict` behind an uncommitted one or inside a Messenger wrapper, a
  `HandlerFailedException` after another handler succeeded (`HandledStamp`), and any `DelayedMessageHandlingException`.
  Each one is work that may already be committed, so 1.6.0's `CONFLICT_001` invited a blind retry. A test that
  asserted `CONFLICT_001` for one of these needs `CONFLICT_002`. Nothing changes for a failure without such a
  conflict or wrapper.
- **`forHostEntityManager()` refusal:** the `LogicException` may now have a previous exception (what
  `computeChangeSets()` threw). Code that matched the message keeps working.
- **`forEntityManager()` is for bundle-owned managers only:** if a bundle passes the host's (default, shared) manager,
  switch to `forHostEntityManager()` (same arguments). `forEntityManager()` keeps closing and resetting an open
  manager after a storage failure; no option was added (see the 1.6.1 CHANGELOG).

## To 1.6.0

From **1.5.0**:

```bash
composer update nowo-tech/transaction-doctrine
```

`composer update nowo-tech/transaction-doctrine`. No configuration change, and no public signature changed: every
addition is a new class, method or optional argument.

### Request commit watermark

With `commit_detection: true` (the default) a conflict after a commit **earlier in the same request** now answers
`CONFLICT_002` (JSON `error` `concurrency_conflict_partial`, the "reload" page, **no `Retry-After`**) where 1.5
answered `CONFLICT_001` + `Retry-After`. This is the intended fix (audit TH X-1); a test that asserted 1.5's
`CONFLICT_001` after an earlier commit in the request needs the new code. Requests that commit nothing before the
conflict answer exactly as before. With `commit_detection: false` nothing changes.

Bundles that build their own conflict answer should move from `ConflictCode::forFailure($failure)` to
`ConflictCode::forRequest($request, $failure)` to get the same rule; `forFailure()` is unchanged when called with one
argument. A bundle that kept its own request mark (for example a ledger mark at `kernel.request`) can keep it as a
fallback: both agree.

### X-Conflict-Code header

Every answer of the responder gains the header `X-Conflict-Code: CONFLICT_001` or `CONFLICT_002`. Additive: the body,
status, `Retry-After` and `Cache-Control` are unchanged. A formatter's own `X-Conflict-Code` is overwritten with the
platform value. `ConflictResponsePolicy::headers()` and `httpException()` return what they did in 1.5 (no
`X-Conflict-Code`, so exact-array assertions keep passing); move to `headersFor($code, $hint)` and
`httpExceptionFor($code, $message, $previous, $hint)` to send the header and drop `Retry-After` for `CONFLICT_002`.
Cross-origin APIs should add `X-Conflict-Code` to `Access-Control-Expose-Headers`. Clients should key on `code` or the
header, never on `error`.

### forHostEntityManager() for the host's entity manager

`DoctrineTransactionRunner::forEntityManager()` behaves as in 1.5 and is for **bundle-owned** entity managers: after a
storage failure it closes and resets the manager, even when it is still open. A bundle that passes the **host's**
manager (the default one, shared with the application) should switch to
`DoctrineTransactionRunner::forHostEntityManager()` (same arguments) to meet REQ-RUNTIME-003 rule 2: an open manager is
never closed, cleared or reset; a closed one is replaced through the registry; only what the failed run brought in is
detached. After a failed run the caller's entities keep what the operation changed on them, so do not modify the
host's entities inside the unit, or `refresh()` them after a failure.

**The host's manager must be clean when `run()` starts.** With pending changes (an edited entity, a `persist()`, a
`remove()`), `forHostEntityManager()->run()` throws `LogicException` before opening a transaction: a flush inside the
unit, or the runner's own flush, would carry that work into a transaction that may roll back. Flush (or clear) the
host's work before calling a bundle that uses this runner, as with `WriteUnit`'s run-once rule. The check costs one
`UnitOfWork::computeChangeSets()` per `run()`, which scans the identity map and cascade-persists new entities it
reaches.

### DBAL 3: SQLSTATE codes in driver errors

On DBAL 3, a driver error whose `getCode()` is a SQLSTATE string (DBAL 3's `Driver\PDO\PDOException`, for example a
PostgreSQL serialization failure or a deferred unique violation at COMMIT) used to raise a `TypeError` in the
classifier (1.1.0 to 1.5.x). It is now classified like on DBAL 4: a 40001 at COMMIT becomes `TransientStorageFailure`
and a write unit replays it; any other error is rethrown unchanged. No change is needed; code that caught the
`TypeError` gets the real failure.

## To 1.5.0

From **1.4.0**:

```bash
composer update nowo-tech/transaction-doctrine
```

From **1.4.0**: `composer update nowo-tech/transaction-doctrine`. No configuration change; the default status stays
**409** (it has been since 1.3.0), and `conflict_response.status: 503` keeps working.

### Conflict codes in conflict answers

Every conflict answer now carries a REQ-RUNTIME-003 code (`Nowo\TransactionDoctrine\ConflictCode`): `CONFLICT_001`
when nothing was committed (with `Retry-After`), `CONFLICT_002` when part of the unit was (no `Retry-After`). The change
is additive:

- **JSON** gains a `code` key; `error` and `message` are unchanged:
  `{"error": "concurrency_conflict", "code": "CONFLICT_001", "message": "…"}` and
  `{"error": "concurrency_conflict_partial", "code": "CONFLICT_002", "message": "…"}`. Clients that compare the whole
  body (tests asserting an exact array) need the new key.
- **HTML**: the `data-testid="concurrency-conflict"` element gets `data-conflict-code`. The template receives
  `conflict_code`. **An overridden template** (`templates/bundles/NowoTransactionDoctrineBundle/conflict.html.twig` or
  `conflict_response.template`) should add `data-conflict-code="{{ conflict_code }}"` to keep the code testable.
- **Formatters**: `ConflictResponseContext` has a new `code` property (the constructor's new last argument is optional
  and derived from `committed`); put `$context->code->value` in your own shape.
- **Log**: the responder's warning context gains `code`.
- **Opt-in mapper**: with `map_transient_failures: true`, a lost savepoint with no deadlock behind it (MySQL / MariaDB
  1305 naming a SAVEPOINT, PostgreSQL 3B001) is now answered 409 `CONFLICT_002` instead of a 500. With the default
  `false` nothing changes for it.
- **Serialization failures are replayed by default** (owner decision 2026-11-18): the default `replay_on` (and
  `WriteUnitPolicy`'s default kinds, `WriteUnitPolicy::DEFAULT_REPLAY_KINDS`) is now `[deadlock, lock_wait_timeout,
  serialization_failure]`. A PostgreSQL 40001 or MariaDB 1020 inside a write unit used to be rethrown at once as the
  DBAL exception (or the runner's `TransientStorageFailure`), which the responder did not answer (a 500 unless
  `map_transient_failures` was on). Now the unit is replayed; when every attempt fails it throws `ConcurrencyConflict`
  (kind `serialization_failure`), answered 409 `CONFLICT_001` + `Retry-After`. Code that caught the DBAL
  serialization exception around a write unit should catch `ConcurrencyConflict` (or the contract's
  `TransientStorageFailure`, which it extends). To keep the 1.4 behaviour, list the kinds explicitly; an explicit
  list replaces the default:

  ```yaml
  nowo_transaction_doctrine:
      replay_on: [deadlock, lock_wait_timeout]
  ```

  Programmatic units: `new WriteUnitPolicy(replayKinds: ['deadlock', 'lock_wait_timeout'])`.
- **Commit detection after a refused COMMIT (audit TA2-1)**: fixed; no action. Units that met a conflict after an
  autocommit write on a connection whose earlier COMMIT the server refused now answer `committed: true`
  (`CONFLICT_002`) instead of replaying the write.
- **Write units (audit TA-6)**: when a `ResetBetweenAttemptsInterface` service or a `WriteUnitRetryEvent` listener
  throws, the unit now discards what it left (open transaction levels, pending entity changes) before throwing
  `ConcurrencyConflict`, so the connection is back at nesting level 0. The managers are cleared once more on that path.

## To 1.4.0

From **1.3.1**:

```bash
composer update nowo-tech/transaction-doctrine
```

From **1.3.1**: `composer update nowo-tech/transaction-doctrine`. No configuration change; nothing breaks.

### Request memos and write-unit replays

A write unit clears (or resets) the entity managers before replaying its work. **Request memos that hold entities must
implement `ResetBetweenAttemptsInterface`, or store ids**: otherwise a replay gets the failed attempt's detached
entities and fails with `ORMInvalidArgumentException` (a 500 instead of the 409) or drops its changes silently. With
autoconfiguration nothing else is needed; order memos with `#[AsTaggedItem(priority: …)]` when one derives from another
(see [USAGE](USAGE.md#request-memos-between-attempts-140)). Beacon: LeaveDirectory, LeaveAccess, OrganizationContext and
RoomChannelAccess.

- `WriteUnit::run()` accepts an optional unit name (`run($work, 'invoice_pay')`), carried by `WriteUnitRetryEvent`.
  `WriteUnitInterface::run()` keeps its signature in 1.x (adding the argument would break other implementations); it
  gains `?string $unit = null` in 2.0. The bundle's controller listener and Messenger middleware pass the name only to
  `WriteUnit`, so a decorator of `WriteUnitInterface` still works (its replays carry no unit name).
- `ConcurrencyConflict` has a new `$resetFailure` (null unless a resetter or retry listener threw before a replay).
- Code that builds `WriteUnit` by position keeps working: the new `$resetters` and `$dispatcher` arguments come last.

## To 1.3.1

From **1.3.0**:

```bash
composer update nowo-tech/transaction-doctrine
```

## To 1.3.0

From **1.2.0**:

```bash
composer update nowo-tech/transaction-doctrine
```

### To 1.3.0

From **1.2.0**:

```bash
composer update nowo-tech/transaction-doctrine
```

The library API is unchanged (runner, classifier, repairer). New, opt-in:

- write units (`WriteUnit`, `WriteUnitInterface`, `WriteUnitPolicy`, `#[AsWriteUnit]`, `ConcurrencyConflict`,
  `LockConflictExceptionInterface`, `DoctrineStateRepairer`);
- an optional Symfony bundle, `Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle`. It is **not**
  registered by itself (the package type stays `library`): add it to `config/bundles.php` to get the `#[AsWriteUnit]`
  listener, the Messenger middleware service, the lock-conflict responder, worker connection hygiene and the shared
  `ConflictResponsePolicy`. See [Installation](INSTALLATION.md#symfony-bundle-optional) and
  [Configuration](CONFIGURATION.md#symfony-bundle).

Without the bundle registered nothing changes in your application.

### Default lock-conflict status: 409

Owner platform decision (Beacon re-audit 2026-11-17, OTHER REQ-SCALE-002): **one lock-conflict status, 409 Conflict +
`Retry-After`**. With the bundle registered:

- a write unit that still conflicts on its last attempt is answered **409** with `Retry-After: 1`;
- `ConflictResponsePolicy::$status` (service `nowo_transaction_doctrine.conflict_response_policy`) is **409**, and the
  bundles that read it (ChatKit, Wiki and TimeTrack from their next minor) move their transient-failure answer from
  503 to 409 with it.

Clients that already retry on 503 + `Retry-After` must also retry on 409 + `Retry-After`. A host that relies on 503
keeps it, for the responder and for every bundle that reads the policy:

```yaml
# config/packages/nowo_transaction_doctrine.yaml
nowo_transaction_doctrine:
    conflict_response:
        status: 503        # 409 (default) or 503
        retry_after: 1
```

### Migration notes for ChatKit, Wiki and TimeTrack

Each bundle keeps its JSON field names; only the status changes, read from the shared policy. In each bundle:

1. `composer.json`: `"nowo-tech/transaction-doctrine": "^1.3"`.
2. Inject `?Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy` into the storage-failure listener
   (`service('nowo_transaction_doctrine.conflict_response_policy')->nullOnInvalid()`), falling back to
   `new ConflictResponsePolicy()` (409, 1 s) when the host did not register the bundle.
3. Never invite a blind retry after a partial commit: when the failure (or its chain) holds a
   `Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict` with `committed: true`, send **no** `Retry-After`
   (do not call `$policy->headers()`; its `retryAfterSeconds()` is `null`) and tell the client to reload before trying
   again. Only conflicts that did not commit get `$policy->headers($hint)`.
4. Replace the hard-coded 503:
   - JSON: `Response::HTTP_SERVICE_UNAVAILABLE` → `$policy->status`, and `['Retry-After' => (string) $retry]` →
     `$policy->headers($hint)` (`$hint` = `TransientStorageFailure::retryAfterSeconds()`, or `null` for a bare DBAL
     `RetryableException`);
   - HTML: `new ServiceUnavailableHttpException($retry, $message, $failure)` →
     `$policy->httpException($message, $failure, $hint)`.
5. Ship a `ConflictResponseFormatterInterface` (autoconfigured when the interface exists) that answers the bundle's
   own requests in its own JSON shape. Without it, a host that lists the bundle's routes in `controller_routes` gets
   this package's `{"error": "concurrency_conflict"}` shape when the unit gives up, because the responder answers
   first.
6. UPGRADING of the bundle: "transient storage failures answer 409 + Retry-After (was 503); set
   `nowo_transaction_doctrine.conflict_response.status: 503` to keep 503". Also say that hosts that never register
   `NowoTransactionDoctrineBundle` move to 409 too (the fallback policy), and to keep 503 they must register it and set
   the status. Bump the minor version.

| Bundle | Class | JSON shape kept | 1.2 status → 1.3 default |
|--------|-------|-----------------|--------------------------|
| ChatKit | `Http\ChatApiExceptionListener::onStorageFailure()` | `{ok: false, error: "unavailable"}` | 503 → 409 |
| Wiki | `Transaction\WikiStorageFailureSubscriber` (`onApiException`: `{err, ECODE: "SERVER_002"}`; `onException`: `{error: "unavailable"}`) | both | 503 → 409 |
| TimeTrack | `EventSubscriber\StorageFailureSubscriber` | `{error: "unavailable", message}` (client API, CORS headers kept) | 503 → 409 |

Their unique-key "conflict" answers (409 without `Retry-After`) are unchanged.

## To 1.2.0

From **1.1.0**:

```bash
composer update nowo-tech/transaction-doctrine
```

Nothing to do. The logger is optional: `DoctrineTransactionRunner::forConnection()` / `forEntityManager()` and
`ConnectionRepairer::__construct()` take a trailing `?LoggerInterface` that defaults to a `NullLogger`, so without one
the behaviour is unchanged. If you pass one, do not log to the same database connection the runner uses (the line is
lost inside the failed transaction), and note that `exception_code` of a domain exception is whatever your code set.

## 1.0 to 1.1

No code change is required.

- `doctrine/dbal ^3.8.2` is now accepted besides `^4.0`. On DBAL 3 with emulated nesting (savepoints off) nested
  leftovers are rolled back level by level instead of closing the connection.
- **A domain failure no longer resets a shared entity manager.** After a refusal that left nothing pending, only the
  entities that entered the identity map during the run are detached; the caller's other entities stay managed. If
  your code relied on the manager being reset after *any* failure, re-load what you need after catching the
  exception (storage failures still close and reset the manager as before).
- After catching a failure from a **nested** `run()`, do not continue the same unit of work: the nested failure can
  clear the caller's pending changes.
- `ConnectionRepairer` has a new optional constructor argument `?bool $emulatedNesting = null` (detected by default).

## 1.0.0

First release. Requires `nowo-tech/transaction-contract ^1.0`.
