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
  | Domain failure, and the operation changed or persisted something, or flushed inside the runner's transaction | **Cleared** (same instance): its writes were rolled back, so the identity map is stale |
  | Domain failure that left nothing pending (a plain refusal) | **Untouched**: the host's entities stay managed |

  Nothing the failed operation persisted is committed by a later flush, `run()` or request (with FrankenPHP and kernel
  reset off, the manager lives across requests). Clearing or resetting a shared manager also detaches the caller's
  entities and discards its own pending changes, as `EntityManager::wrapInTransaction()` does: do not keep using
  entities loaded before a failed `run()` unless the failure was a plain refusal.

Transient failures (1213, 1205, MariaDB 1020, SQLSTATE 40001 / 40P01 / 55P03, any DBAL `RetryableException`) become
`TransientStorageFailure` with the storage error as `previous`; anything else is rethrown unchanged. A 1305 lost
savepoint is transient only through the deadlock it carries: on its own (an implicit commit dropped the savepoints)
it is rethrown. A connection lost during the commit is not transient (unknown outcome).

Supports `doctrine/dbal ^3.8.2 || ^4.0`; the entity manager variant works with ORM 2.20+ and 3.x. CI runs the unit
and real-database suites on DBAL 4 + ORM 3, DBAL 3 + ORM 3 and DBAL 3 + ORM 2. On DBAL 3 the repair is only needed
when savepoints are enabled (`setNestTransactionsWithSavepoints(true)`): with DBAL 3's emulated nesting a nested
rollback never fails with 1305. Register the runner under **your own service id** and alias
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
