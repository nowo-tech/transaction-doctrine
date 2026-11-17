# Transaction Doctrine

[![CI](https://github.com/nowo-tech/transaction-doctrine/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/transaction-doctrine/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/transaction-doctrine.svg?style=flat)](https://packagist.org/packages/nowo-tech/transaction-doctrine) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/transaction-doctrine.svg)](https://packagist.org/packages/nowo-tech/transaction-doctrine) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/transaction-doctrine.svg?style=social&label=Star)](https://github.com/nowo-tech/transaction-doctrine) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install from [Packagist](https://packagist.org/packages/nowo-tech/transaction-doctrine) and give the repo a [star on GitHub](https://github.com/nowo-tech/transaction-doctrine) if it helps you.

Safe plain-Doctrine default for [`nowo-tech/transaction-contract`](https://github.com/nowo-tech/transaction-contract):
`DoctrineTransactionRunner` implements `TransactionRunnerInterface` with **one attempt and no retry**, for a bundle
to use when the host wires no adapter. No Symfony; `doctrine/dbal` required, `doctrine/orm` and
`doctrine/persistence` optional.

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

## Installation

```bash
composer require nowo-tech/transaction-doctrine
```

Supports `doctrine/dbal ^3.8.2 || ^4.0`; the entity manager variant works with ORM 2.20+ and 3.x. Details:
[Installation](docs/INSTALLATION.md).

## Usage

```php
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

$runner = DoctrineTransactionRunner::forEntityManager($entityManager, $managerRegistry, 'default');
$runner->run(fn () => $orders->get($id)->pay($amount));   // begin, run, flush, commit
```

After **any** failure it leaves neither a poisoned connection nor pending changes: the levels it opened are rolled
back to the entry level (the connection is closed only when that rollback fails), and the entity manager has no
pending changes from the failed operation, with a shared, host-owned manager disturbed only as much as that requires.
Transient failures (deadlocks, lock waits, serialization failures) become `TransientStorageFailure`; anything else is
rethrown unchanged. The full behaviour reference (failure table, nested runs, DBAL 3 emulated nesting, wiring) is in
[Usage](docs/USAGE.md).

Also exported: `TransientFailureClassifier`, `TransientFailureKind`, `ConnectionRepairer` (the same logic as
`nowo-tech/doctrine-deadlock-retry-bundle` 3.0).

## Logging (optional)

Pass a PSR-3 logger as the last argument; the default is a `NullLogger`, so nothing changes when you do not:

```php
$runner = DoctrineTransactionRunner::forEntityManager($entityManager, $managerRegistry, 'default', 1, $logger);
$repairer = new ConnectionRepairer(null, $logger);
```

| Event | Level |
| --- | --- |
| Operation failed, classified transient (deadlock, lock wait, ...) | notice |
| Operation failed, not transient | debug |
| Levels rolled back to the entry level | notice |
| Rollback failed, connection closed (session state lost) | warning |
| Entity manager closed and reset through the registry | notice |
| Entity manager cleared | debug / notice |

Context is structured and safe: exception class and code, nesting levels, action, manager name. SQL, parameters and
exception messages are never logged. A logger that throws is ignored, and the runner stays stateless, so one instance
is safe in a long-running worker.

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [PSR evaluation (REQ-CS-007)](docs/PSR.md)

## Tests and coverage

- **Tests:** PHPUnit: unit tests (`tests/Unit`) plus a real-database suite (`tests/Integration/Database`: MySQL 8.4,
  MariaDB 11.4, PostgreSQL 16) that runs when `TD_MYSQL_URL`, `TD_MARIADB_URL` and `TD_POSTGRES_URL` are set
  (`make test-with-db`; the CI job "Real databases" runs it on DBAL 4 + ORM 3, DBAL 3 + ORM 3 and DBAL 3 + ORM 2).
- **PHP (lines):** **100%** of `src/` (121/121 lines, 23/23 methods; CI gate on PHP 8.2; locally `make test-coverage`).
- **TS/JS:** N/A
- **Python:** N/A

## License

MIT. See [LICENSE](LICENSE).
