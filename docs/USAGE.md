# Usage

## Table of contents

- [Run a unit of work](#run-a-unit-of-work)
- [Behaviour after a failure](#behaviour-after-a-failure)
- [Wiring in a bundle](#wiring-in-a-bundle)
- [Standard Symfony host and DDD / hexagonal host](#standard-symfony-host-and-ddd--hexagonal-host)
- [FrankenPHP worker mode](#frankenphp-worker-mode)
- [Write units (1.3.0)](#write-units-130)
  - [Programmatic API](#programmatic-api)
  - [Controllers: `#[AsWriteUnit]`](#controllers-aswriteunit)
  - [Messenger middleware](#messenger-middleware)
  - [Request memos between attempts (1.4.0)](#request-memos-between-attempts-140)
- [Lock-conflict answer: 409 + Retry-After](#lock-conflict-answer-409--retry-after)
  - [Exception-mapping and response hooks](#exception-mapping-and-response-hooks)
  - [Bundles that answer in their own shape](#bundles-that-answer-in-their-own-shape)
- [Worker connection hygiene](#worker-connection-hygiene)

## Run a unit of work

```php
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

$runner = DoctrineTransactionRunner::forEntityManager($entityManager, $managerRegistry, 'default');
$runner->run(fn () => $orders->get($id)->pay($amount));   // begin, run, flush, commit
```

## Behaviour after a failure

After **any** failure the runner leaves neither a poisoned connection nor pending changes:

- the levels it opened are **rolled back** to the entry level; the connection is **closed only when that rollback
  fails** (after a MySQL / MariaDB deadlock in a nested transaction the server dropped the savepoints, so
  `ROLLBACK TO SAVEPOINT` fails with 1305 and DBAL would keep the nesting level up). An ordinary failure, such as a
  validation exception, keeps session state: variables, `GET_LOCK()`, PostgreSQL advisory locks;
- the entity manager has **no pending changes** from the failed operation, and a shared, host-owned manager is
  disturbed only as much as that requires:

  | Failure | Entity manager after the failure |
  |---------|----------------------------------|
  | Storage failure (a DBAL / driver error in the chain), or the ORM closed the manager | Closed and reset through the registry; cleared when no registry is given (its state cannot be trusted) |
  | Domain failure after the operation flushed inside the runner's transaction, or re-hydrated an entity that was already managed (`refresh()`, a proxy initialised) | **Cleared** (same instance): the identity map holds rolled-back data |
  | Domain failure that left changes pending (persist, remove, a changed entity) | **Cleared** (same instance) |
  | Domain failure otherwise (a refusal) | Every entity that **entered the identity map during the run** (loaded, or a `getReference()` proxy) is **detached**, since it may hold rows or values the rollback undid; the caller's other entities stay managed. If that detach cascades (`cascade: ['detach']`) into a caller's entity, the manager is **cleared** instead, so no caller entity silently drops out alone |

  The runner watches the run through a `postFlush` / `postLoad` listener attached for that run only (removed in
  `finally`), and snapshots the identity map when the run starts (a walk over the identity map on every `run()` of
  the entity manager variant; about 3 ms for 50,000 managed entities); on a refusal it compares the identity map with
  that snapshot.

  **Pending-change check.** Scheduled insertions, deletions and collection changes are checked first; only when there
  are none does the runner call `UnitOfWork::computeChangeSets()`. That call scans the whole identity map and, through
  cascade-persist associations, persists new entities it reaches: their `prePersist` callbacks run, and generated ids
  (sequences, UUIDs) may be assigned. A caller's not-yet-persisted draft reachable that way counts as pending, so a
  refusal then clears it too. A unit of work that cannot compute its change sets counts as pending.

  **Nested runs.** A `run()` inside another one shares the caller's unit of work, and the pending-change check cannot
  tell the caller's changes from the inner run's: a nested domain failure that clears the manager **discards the
  caller's pending changes too**. After catching a failure from a nested `run()`, do not continue the same unit of
  work: let the failure propagate, or re-load and redo the work. (With DBAL 3's emulated nesting the caller's
  transaction is also marked rollback-only, so its commit fails.)

  Nothing the failed operation persisted is committed by a later flush, `run()` or request (with FrankenPHP and kernel
  reset off, the manager lives across requests). Clearing or resetting a shared manager detaches the caller's
  entities and discards its own pending changes, as `EntityManager::wrapInTransaction()` does: after a failed `run()`
  keep using the caller's entities only when the failure was a refusal that left nothing pending.

Transient failures (1213, 1205, MariaDB 1020, SQLSTATE 40001 / 40P01 / 55P03, any DBAL `RetryableException`) become
`TransientStorageFailure` with the storage error as `previous`; anything else is rethrown unchanged. A 1305 lost
savepoint is transient only through the deadlock it carries: on its own (an implicit commit dropped the savepoints)
it is rethrown. A connection lost during the commit is not transient (unknown outcome).

Supports `doctrine/dbal ^3.8.2 || ^4.0`; the entity manager variant works with ORM 2.20+ and 3.x. CI runs the unit
and real-database suites on DBAL 4 + ORM 3, DBAL 3 + ORM 3 and DBAL 3 + ORM 2. With savepoints (DBAL 4, or DBAL 3
with `setNestTransactionsWithSavepoints(true)`) a nested leftover is closed when its `ROLLBACK TO SAVEPOINT` fails.
With DBAL 3's emulated nesting (the DBAL 3 default) a nested rollback issues no SQL and only marks the transaction
rollback-only, a flag `Connection::close()` does not reset: the repairer therefore rolls such leftovers back level by
level (the real level-1 `ROLLBACK` clears the flag) and never closes them; if that `ROLLBACK` itself fails, it closes
and then begins and rolls back once to clear the flag. Register the runner under **your own service id** and alias
`TransactionRunnerInterface` to it only when the interface is not defined yet, so a host's retrying adapter wins.

Also exported: `TransientFailureClassifier`, `TransientFailureKind`, `ConnectionRepairer` (the same logic as
`nowo-tech/doctrine-deadlock-retry-bundle` 3.0, which could depend on this package instead of carrying a copy).

## Wiring in a bundle

The runner is the default a bundle uses when the host wires no adapter of its own. Register it under **your own
service id** and alias `Nowo\TransactionContract\TransactionRunnerInterface` to it only when the interface is not
defined yet, so a host's retrying adapter (for example `nowo-tech/doctrine-deadlock-retry-bundle` 3.0) wins.

```php
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

// DBAL only
$runner = DoctrineTransactionRunner::forConnection($connection);

// Entity manager; the registry and manager name let the runner reset a closed manager
$runner = DoctrineTransactionRunner::forEntityManager($entityManager, $managerRegistry, 'default');
```

## Standard Symfony host and DDD / hexagonal host

- **Standard Symfony host:** the bundle that depends on this package builds the runner from the default entity
  manager (or its own) and the `doctrine` registry.
- **DDD / hexagonal host:** application code depends only on `TransactionRunnerInterface` from
  `nowo-tech/transaction-contract` (no Doctrine types); this package is the infrastructure adapter behind it.

## FrankenPHP worker mode

The runner is `readonly` and keeps no state between calls; the per-run observer is attached to the entity manager's
event manager for one run and removed in `finally`. With kernel reset off the entity manager lives across requests,
which is why a failed run never leaves pending changes behind (see the table above).

## Write units (1.3.0)

A **write unit** is one host-level unit of work (a controller action, a consumed message, a command step) that is
**replayed after a lock conflict**: a deadlock (MySQL / MariaDB 1213, PostgreSQL 40P01) or a lock wait timeout (1205,
PostgreSQL 55P03) by default. The conflict is found anywhere in the failure's previous chain (a bundle runner's
`TransientStorageFailure` included) or inside a Messenger `HandlerFailedException`; the kind comes from
`TransientFailureClassifier`.

- At most `max_attempts` attempts (3), with a **full-jitter** pause between two of them: uniform between `floor_ms`
  and `min(cap_ms, base_ms × 2^(attempt − 1))` (20–100 ms, then 20–200 ms).
- Between two attempts the transaction levels the unit left open are rolled back (`ConnectionRepairer`), closed
  entity managers are reset and open ones cleared (`DoctrineStateRepairer`): an attempt never sees what the failed one
  loaded.
- **Outermost unit only:** when any connection of the registry is already inside a transaction (the caller's or an
  outer unit's), the work runs once and a failure propagates unchanged, because the database may have rolled back the
  caller's whole transaction. A `ConcurrencyConflict` of an inner unit, even wrapped (in the previous chain or inside
  a Messenger `HandlerFailedException`), is never replayed again by an outer one.
- **Pending changes:** when an open entity manager already holds unflushed work before the first attempt (a caller's
  edits or `persist()` calls), the work runs once without replay: clearing the manager would lose them silently.
- **A replay detaches everything the caller loaded:** between two attempts the open entity managers are cleared, so
  entities the caller held before `run()` are no longer managed afterwards. Load again what you change after the unit.
- **Messenger wrappers:** a `HandlerFailedException` (or `DelayedMessageHandlingException`) is replayed only when
  **every** wrapped exception is a replayable conflict and no handler already succeeded (`HandledStamp`); a domain
  error next to a deadlock goes out unchanged, so the failure transport and `ErrorDetailsStamp` keep the real error.
- The last conflict becomes `ConcurrencyConflict` (a `TransientStorageFailure` of the contract, so existing handlers
  keep working; `attempts`, `kind`, `retryAfterSeconds()`, the storage error as previous, a constant client-safe
  message). Any other failure is rethrown unchanged at once.
- The work must be **replayable**: it loads what it changes itself and keeps side effects (mails, messages, pushes)
  until after its commit, the same contract as a Messenger retry.
- **A unit must commit at most once, or be idempotent as a whole.** A replay runs the whole unit again: what an
  earlier commit of the failed attempt wrote, and the side effects that followed it, would happen twice. With
  **commit detection** (`commit_detection: true`, the default with the bundle: a DBAL middleware tagged
  `doctrine.middleware` feeds a `CommitLedger`), a failed attempt that already committed (a `COMMIT`, or a write
  statement outside a transaction) is **not replayed**: the conflict is rethrown at once as a `ConcurrencyConflict`
  with `committed: true`. It is still answered **409**, but never as a safe retry: **no `Retry-After`**, the JSON code
  `concurrency_conflict_partial` (`<json_error>_partial`) and the text "Part of your change may have been saved …
  Reload before trying again." with a *Reload* link; formatters see `ConflictResponseContext::$committed` and a null
  `retryAfterSeconds`. In a Messenger worker it goes out wrapped in an `UnrecoverableMessageHandlingException`, so the
  transport does not retry it and the message lands in the failure transport. Without the bundle, pass a
  `CommitLedger` to `WriteUnit` and add `new CommitTrackingMiddleware($ledger)` to your DBAL configuration's
  middlewares, or keep to one commit per unit.
- **What commit detection sees, and its limits.** Counted: every successful `COMMIT`; outside a transaction, every
  statement that does not start with `SELECT`, `SHOW`, `SET`, `EXPLAIN` (except `EXPLAIN ANALYZE`, which runs the
  statement), `DESCRIBE` or `PRAGMA`; any SQL string holding several statements (a `;` before the trailing
  whitespace); and on MySQL / MariaDB the statements that commit implicitly even inside a transaction (`CREATE`,
  `ALTER`, `DROP`, `TRUNCATE`, `RENAME`, `LOCK` / `UNLOCK`, `GRANT` / `REVOKE`, `BEGIN`, `START TRANSACTION`,
  `ANALYZE`, `OPTIMIZE`, `REPAIR`, `FLUSH`…). **Not seen:** a `SELECT` that calls a writing function or stored
  routine, and anything run through the raw PDO / mysqli handle (`getNativeConnection()`), which bypasses DBAL. Counting
  too much only cancels a replay; keep writes that the ledger cannot see out of write units.
- **One retry layer:** use a write unit **or** another retry (for example `nowo-tech/doctrine-deadlock-retry-bundle`'s
  retry service or its retrying flush), not both nested: the inner retry rethrows the raw deadlock after its own
  attempts and the outer unit replays it again, multiplying the attempts.

### Programmatic API

```php
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;

// Symfony: autowire WriteUnitInterface (service nowo_transaction_doctrine.write_unit).
// Plain PHP: new WriteUnit($managerRegistry, new WriteUnitPolicy(maxAttempts: 3), null, $clock, $logger, null, $ledger);
$total = $writeUnit->run(function (int $attempt, ?Throwable $previous) use ($orderId): Money {
    return $runner->run(fn () => $orders->get($orderId)->recalculate());   // reloads on every attempt
});
```

`WriteUnitInterface` has no Doctrine or Symfony type in its signature, so a DDD / hexagonal application service can
depend on it; `WriteUnit` is the Doctrine adapter behind it.

### Controllers: `#[AsWriteUnit]`

```php
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;

final class TaskController
{
    #[AsWriteUnit]
    #[Route('/tasks/{id}/move', methods: ['POST'])]
    public function move(Task $task, Request $request): Response { /* ... */ }
}
```

The listener runs after the security attribute listeners and the request payload resolvers
(kernel.controller_arguments, priority -10100). On a replay:

- only the **top-level Doctrine entity arguments** are reloaded, by identifier, from the cleared or reset manager (an
  entity that no longer exists answers 404). Entities inside an array or collection argument (a custom resolver) are
  not reloaded: they stay detached, and writing them into an association fails the replay (a 500, nothing written);
  an unsaved entity built by a resolver (no identifier) answers 404 on a replay. Every other argument keeps its first-attempt value: `#[MapRequestPayload]` /
  `#[MapQueryString]` DTOs, `#[MapUploadedFile]` files, the `Request`, values another listener set;
- a signed-in user that is an entity is reloaded into the token; when it no longer exists the replay **fails closed**
  (403) instead of running with the stale user;
- access checks (`#[IsGranted]` and the other attribute listeners) are **not repeated**: they ran once on the
  first-attempt entities, and the replay acts on the same rows reloaded, whose state may have changed meanwhile (the
  write that caused the conflict). Put checks that depend on that state inside the action.

For controllers you cannot annotate, list the route names. An entry matches the route name **exactly**; `*` is an
explicit wildcard (for tenant-prefixed copies of a route):

```yaml
nowo_transaction_doctrine:
    controller_routes:
        - app_invoice_pay                   # exact
        - '*_app_invoice_pay'               # org_app_invoice_pay, general_app_invoice_pay, …
```

List only actions you know commit at most once (or are idempotent). Listing **a bundle's route** also needs that
bundle's `ConflictResponseFormatterInterface`: the responder answers at priority 64 and stops the event, so without a
formatter the bundle's API clients get this package's JSON shape (`{"error": "concurrency_conflict"}`) instead of the
bundle's own when the unit gives up. ChatKit, Wiki and TimeTrack ship formatters in their next minor; until then do
not list their routes.

### Messenger middleware

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        buses:
            messenger.bus.default:
                middleware:
                    - nowo_transaction_doctrine.write_unit_middleware   # before doctrine_transaction
```

Every message a **worker consumes** (`ConsumedByWorkerStamp`) runs as one write unit: a lock conflict is replayed
in-process after a few milliseconds instead of waiting for the transport's retry delay. Messages handled synchronously
inside a web request are left to the request. A message whose other handler already succeeded, or whose failure came
from messages dispatched after the handler (`DelayedMessageHandlingException`), is not replayed in-process. After the
last attempt the `ConcurrencyConflict` goes out inside a `HandlerFailedException`, so retry strategies and the failure
transport see the usual shape.

### Request memos between attempts (1.4.0)

Between two attempts the unit clears every open entity manager (or resets a closed one): every entity loaded by the
failed attempt is **detached**. A per-request (or per-message) memo that kept one of them hands the replay a detached
entity: writing it into an association fails the flush with `ORMInvalidArgumentException` (a 500 instead of the
conflict answer), and changes made to it are silently never written.

**Request memos that hold entities must implement `ResetBetweenAttemptsInterface`, or store ids.**

```php
use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;
use Symfony\Contracts\Service\ResetInterface;

final class OrganizationContext implements ResetBetweenAttemptsInterface, ResetInterface
{
    private ?Organization $organization = null;

    public function __construct(private readonly OrganizationRepository $organizations, private readonly RequestStack $requests)
    {
    }

    public function current(): Organization
    {
        return $this->organization ??= $this->organizations->get($this->requests->getMainRequest()?->attributes->get('org'));
    }

    public function resetBetweenAttempts(): void   // before a write-unit replay
    {
        $this->organization = null;
    }

    public function reset(): void                  // between requests (kernel.reset)
    {
        $this->organization = null;
    }
}
```

- With autoconfiguration the service is tagged `nowo_transaction_doctrine.reset_between_attempts` (constant
  `ResetBetweenAttemptsInterface::TAG`). Without it, tag the service yourself.
- **Order:** tag priority, higher first, then registration order: `#[AsTaggedItem(priority: 10)]` on the class, or a
  `priority` attribute on the tag. Reset a memo that others derive from after them (lower priority).
- **When:** after the failed attempt's managers were cleared or reset and after the pause, right before the next
  attempt; for every entry point (`#[AsWriteUnit]`, `controller_routes`, the Messenger middleware, the programmatic
  `WriteUnitInterface` service). **Never** before the first attempt, never after the last failure (no replay follows;
  emptying the memo between requests is your own `ResetInterface` / `kernel.reset`, which does **not** run in a
  FrankenPHP worker with kernel reset off, so a memo there must be request-scoped some other way), never when the unit runs once (inside a transaction, pending
  changes, an attempt that already committed).
- **Failure:** an exception thrown by a resetter abandons the replay. The unit gives up with `ConcurrencyConflict`
  (409 + `Retry-After`; for a message, a `HandlerFailedException` the transport retries): its previous exception is
  the attempt's lock conflict, and `ConcurrencyConflict::$resetFailure` holds the resetter's exception. An error is
  logged. Keep resetters to dropping references; reload lazily on the next read.
- **Leftovers:** a resetter (or retry listener) that leaves a transaction open or entity changes pending would make the
  replay run nested (its flush never commits) or write those changes. The unit discards them and gives up the same way,
  `$resetFailure` being a `LogicException`. Never open transactions, flush or persist in a resetter.
- A **Generator** passed as `$resetters` (plain PHP) is read once into a list; a Symfony tagged iterator is rewindable.
- Storing **identifiers** instead of entities needs no hook: the replay loads them again from the current manager.

The unit also dispatches a PSR-14 `WriteUnitRetryEvent` (on `event_dispatcher`, after the resetters, with the same
timing) for observers (metrics, tracing) or state you cannot tag. It carries `attempt` (the attempt about to run, 2 or
more), `failure`, `kind` and `unit`: the route name for controllers, the message class for consumed messages, or what
a programmatic caller passed as the second argument of `WriteUnit::run($work, 'invoice_pay')`. A listener that throws
abandons the replay like a failing resetter. Listeners should record only `kind`, `unit`, `attempt` and the exception
class (`$event->failure::class`), never `$event->failure->getMessage()`: DBAL messages can carry SQL and parameters.

```php
#[AsEventListener]
final class WriteUnitReplayMetrics
{
    public function __invoke(WriteUnitRetryEvent $event): void { /* count $event->unit, $event->kind */ }
}
```

Plain PHP: `new WriteUnit($registry, $policy, null, $clock, $logger, null, $ledger, [$memo], $dispatcher)`.

## Lock-conflict answer: 409 + Retry-After

When the final attempt still conflicts, the bundle's `ConcurrencyConflictResponder` (kernel.exception, priority 64,
before the bundles' own mappers) answers **409 Conflict** (configurable: `conflict_response.status: 503`) with
`Retry-After` (`conflict_response.retry_after`, 1 s) and `Cache-Control: no-store, private`:

- **JSON** (XHR, a JSON body, `Accept: application/json`, `_format: json`, or a path under `json_path_prefixes`,
  default `/api/`):

  ```json
  {"error": "concurrency_conflict", "message": "Your change was not saved because the same data was being changed at the same time. Nothing was lost: try again."}
  ```

- **HTML:** a branded page "Someone else was saving at the same moment" with **Try again** (the page itself for a GET,
  the same-host page the form was sent from after a POST, else home). Template `@NowoTransactionDoctrine/conflict.html.twig`;
  when `nowo-tech/ui-kit-bundle` is installed it extends UiKit's error page (`@NowoUiKit/error/status.html.twig`).
  Override it in `templates/bundles/NowoTransactionDoctrineBundle/conflict.html.twig` or with
  `conflict_response.template`. Without Twig a plain, escaped page is built.

The responder logs one constant warning (`A write gave up after repeated database lock conflicts; answered the conflict
status.`) with the route name, method, attempts and status; never the URL, SQL or a message.

### Exception-mapping and response hooks

The responder never matches route names. A failure is a conflict when:

1. it (or anything in its previous chain) implements `Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface`
   (`ConcurrencyConflict` does); or
2. a service implementing `ConflictExceptionMapperInterface` says so (autoconfigured, tag
   `nowo_transaction_doctrine.conflict_exception_mapper`):

```php
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictExceptionMapperInterface;

final class BoardLockedMapper implements ConflictExceptionMapperInterface
{
    public function isConflict(Throwable $failure): bool
    {
        return $failure instanceof BoardLockedException;
    }
}
```

A bundle or host shapes the answer of **its own requests** with a `ConflictResponseFormatterInterface` (tag
`nowo_transaction_doctrine.conflict_response_formatter`, highest priority first; return `null` to fall through). It
recognises its requests by its own means (a request attribute its listener set), and the responder still sets the
policy's status, `Retry-After` and `Cache-Control`:

```php
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;

final class TaskApiConflictFormatter implements ConflictResponseFormatterInterface
{
    public function format(Request $request, ConflictResponseContext $context): ?Response
    {
        if (!$request->attributes->getBoolean('_task_api')) {
            return null;
        }

        return new JsonResponse(['err' => $context->message, 'ECODE' => 'CONFLICT_001']);
    }
}
```

`map_transient_failures: true` registers a mapper that treats **any** transient storage failure reaching the kernel
as a conflict (not only write-unit conflicts). It runs before the bundles' own mappers, so it replaces their JSON
shapes: leave it off when you rely on them.

### Bundles that answer in their own shape

ChatKit, Wiki and TimeTrack map a transient storage failure on their own routes in their own JSON shape. They keep
their field names and read only the **status** from the shared `ConflictResponsePolicy` (service
`nowo_transaction_doctrine.conflict_response_policy`), so one setting drives the whole application:

```php
public function __construct(private ?ConflictResponsePolicy $conflictPolicy = null) {}

$policy = $this->conflictPolicy ?? new ConflictResponsePolicy();      // 409 + Retry-After 1 without the bundle
$response = new JsonResponse(['ok' => false, 'error' => 'unavailable'], $policy->status, $policy->headers($hint));
// HTML routes: throw $policy->httpException('The service is temporarily unavailable. Retry later.', $failure, $hint);
```

`headers($hint)` and `retryAfter($hint)` use a failure's own hint (`TransientStorageFailure::retryAfterSeconds()`)
when it has one, else `conflict_response.retry_after`.

## Worker connection hygiene

With `worker_hygiene: true` (default) a closed `EntityManager` never survives into another request or message, and
a connection that failed is checked before the next unit (FrankenPHP worker mode with kernel reset off):

| Event | Priority | Action |
|-------|----------|--------|
| `kernel.request` (main) | 4096 | Reset closed entity managers (no query) |
| `kernel.exception` | 4096 | A DBAL, driver, PDO or ORM failure marks the request (never answers) |
| `kernel.terminate` | -4096 | After the response of a marked request: reset closed managers, roll back leftover levels, ping each **connected** connection (closed when it no longer answers; DBAL reconnects on the next query) |
| `WorkerMessageFailedEvent` | -4096 | The same repair after a consumed message failed with a database error |

The only SQL it runs is that ping (the platform's dummy select), and only after a database failure. Every service of
the bundle is stateless: the failure mark lives on the request, the wrapped controller on the event, and nothing of
a request, user or tenant is kept on a service.
