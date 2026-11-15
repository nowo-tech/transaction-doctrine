# Transaction Doctrine

Safe plain-Doctrine default for [`nowo-tech/transaction-contract`](https://github.com/nowo-tech/transaction-contract):
`DoctrineTransactionRunner` implements `TransactionRunnerInterface` with **one attempt and no retry**, for a bundle
to use when the host wires no adapter. No Symfony; `doctrine/dbal` required, `doctrine/orm` and
`doctrine/persistence` optional.

```php
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

$runner = DoctrineTransactionRunner::forEntityManager($entityManager, $managerRegistry, 'default');
$runner->run(fn () => $orders->get($id)->pay($amount));   // begin, run, flush, commit
```

After **any** failure it leaves neither a poisoned connection nor pending changes:

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
  | Domain failure otherwise (a refusal) | Only the entities **the run loaded** are **detached** (they may hold rows or values the rollback undid); the caller's other entities stay managed |

  The runner watches the run through a `postFlush` / `postLoad` listener attached for that run only (removed in
  `finally`), and snapshots the identity map's object ids when the run starts (a walk over the identity map on every
  `run()` of the entity manager variant).

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

Tests: unit tests plus a real-database suite (MySQL 8.4, MariaDB 11.4, PostgreSQL 16) that runs when
`TD_MYSQL_URL`, `TD_MARIADB_URL` and `TD_POSTGRES_URL` are set (the CI job "Real databases" sets them).

Also exported: `TransientFailureClassifier`, `TransientFailureKind`, `ConnectionRepairer` (the same logic as
`nowo-tech/doctrine-deadlock-retry-bundle` 3.0, which could depend on this package instead of carrying a copy).



## Documentation

- [Changelog](docs/CHANGELOG.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Security](docs/SECURITY.md)

## License

MIT. See [LICENSE](LICENSE).
