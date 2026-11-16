# Usage

## Table of contents

- [Run a unit of work](#run-a-unit-of-work)
- [Behaviour after a failure](#behaviour-after-a-failure)
- [Wiring in a bundle](#wiring-in-a-bundle)
- [Standard Symfony host and DDD / hexagonal host](#standard-symfony-host-and-ddd--hexagonal-host)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

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
