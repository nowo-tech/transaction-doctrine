# Upgrading

## Table of contents

- [Unreleased](#unreleased)
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
