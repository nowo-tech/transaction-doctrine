# Transaction Doctrine (proposal, not published)

Safe plain-Doctrine default for [`nowo-tech/transaction-contract`](https://github.com/nowo-tech/transaction-contract):
`DoctrineTransactionRunner` implements `TransactionRunnerInterface` with **one attempt and no retry**, for a bundle
to use when the host wires no adapter. No Symfony; `doctrine/dbal` required, `doctrine/orm` and
`doctrine/persistence` optional.

```php
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

$runner = DoctrineTransactionRunner::forEntityManager($entityManager, $managerRegistry, 'default');
$runner->run(fn () => $orders->get($id)->pay($amount));   // begin, run, flush, commit
```

After a failure it never leaves a poisoned connection:

- at nesting level 0, a transaction still active is **closed**, not rolled back (after a MySQL / MariaDB deadlock in
  a nested transaction the rollback fails with 1305 and DBAL 4 keeps the nesting level up);
- inside a caller's transaction, only its own levels are rolled back (closed if that fails);
- a closed entity manager is reset through the registry.

Transient failures (1213, 1205, 40001, 40P01, 1305 on a Doctrine savepoint, any DBAL `RetryableException`) become
`TransientStorageFailure` with the storage error as `previous`; anything else is rethrown unchanged.

Also exported: `TransientFailureClassifier`, `TransientFailureKind`, `ConnectionRepairer` (the same logic as
`nowo-tech/doctrine-deadlock-retry-bundle` 3.0, which could depend on this package instead of carrying a copy).

`composer.json` carries a temporary `path` repository to `../transaction-contract` for local testing; remove it
before publishing.

## License

MIT. See [LICENSE](LICENSE).
