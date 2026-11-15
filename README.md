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
  `ROLLBACK TO SAVEPOINT` fails with 1305 and DBAL 4 would keep the nesting level up). An ordinary failure, such as a
  validation exception, keeps session state: variables, `GET_LOCK()`, PostgreSQL advisory locks;
- the entity manager has **no pending changes**: it is closed and reset through the registry when one is given, and
  cleared otherwise. Nothing the failed operation persisted is committed by a later flush, `run()` or request (with
  FrankenPHP and kernel reset off, the manager lives across requests). Inside a caller's transaction this also
  discards the caller's pending changes, as `EntityManager::wrapInTransaction()` does.

Transient failures (1213, 1205, MariaDB 1020, SQLSTATE 40001 / 40P01 / 55P03, any DBAL `RetryableException`) become
`TransientStorageFailure` with the storage error as `previous`; anything else is rethrown unchanged. A 1305 lost
savepoint is transient only through the deadlock it carries: on its own (an implicit commit dropped the savepoints)
it is rethrown. A connection lost during the commit is not transient (unknown outcome).

Requires DBAL 4 (ORM 3 for the entity manager variant). Register the runner under **your own service id** and alias
`TransactionRunnerInterface` to it only when the interface is not defined yet, so a host's retrying adapter wins.

Tests: unit tests plus a real-database suite (MySQL 8.4, MariaDB 11.4, PostgreSQL 16) that runs when
`TD_MYSQL_URL`, `TD_MARIADB_URL` and `TD_POSTGRES_URL` are set (the CI job "Real databases" sets them).

Also exported: `TransientFailureClassifier`, `TransientFailureKind`, `ConnectionRepairer` (the same logic as
`nowo-tech/doctrine-deadlock-retry-bundle` 3.0, which could depend on this package instead of carrying a copy).

Until `nowo-tech/transaction-contract` is on Packagist, `composer.json` resolves it from its GitHub repository (a
`vcs` repository, `^1.0@dev` through the `dev-main` → `1.0.x-dev` alias); both go once the 1.0.0 tags are published.

## Documentation

- [Changelog](docs/CHANGELOG.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Security](docs/SECURITY.md)

## License

MIT. See [LICENSE](LICENSE).
