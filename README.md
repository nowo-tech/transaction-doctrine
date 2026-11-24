# Transaction Doctrine

[![CI](https://github.com/nowo-tech/transaction-doctrine/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/transaction-doctrine/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/transaction-doctrine.svg?style=flat)](https://packagist.org/packages/nowo-tech/transaction-doctrine) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/transaction-doctrine.svg)](https://packagist.org/packages/nowo-tech/transaction-doctrine) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/transaction-doctrine.svg?style=social&label=Star)](https://github.com/nowo-tech/transaction-doctrine) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install from [Packagist](https://packagist.org/packages/nowo-tech/transaction-doctrine) and give the repo a [star on GitHub](https://github.com/nowo-tech/transaction-doctrine) if it helps you.

Safe plain-Doctrine default for [`nowo-tech/transaction-contract`](https://github.com/nowo-tech/transaction-contract):
`DoctrineTransactionRunner` implements `TransactionRunnerInterface` with **one attempt and no retry**, for a bundle
to use when the host wires no adapter. Since 1.3.0 also **write units** that replay a deadlock, lock wait timeout or
(since 1.5.0) serialization failure, and an **optional Symfony bundle** (`#[AsWriteUnit]`, Messenger middleware, one lock-conflict answer **409 +
`Retry-After`**, worker connection hygiene). `doctrine/dbal` required; `doctrine/orm`, `doctrine/persistence` and the
Symfony packages optional.

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

// The bundle's own entity manager (may be cleared or reset after a failure)
$runner = DoctrineTransactionRunner::forEntityManager($ownManager, $managerRegistry, 'my_bundle');
// The host's entity manager (1.6.0): never cleared or reset while open, replaced only when closed
$runner = DoctrineTransactionRunner::forHostEntityManager($entityManager, $managerRegistry, 'default');
$runner->run(fn () => $orders->get($id)->pay($amount));   // begin, run, flush, commit
```

After **any** failure it leaves neither a poisoned connection nor pending changes: the levels it opened are rolled
back to the entry level (the connection is closed only when that rollback fails), and the entity manager has no
pending changes from the failed operation, with a shared, host-owned manager disturbed only as much as that requires.
Transient failures (deadlocks, lock waits, serialization failures) become `TransientStorageFailure`; anything else is
rethrown unchanged. The full behaviour reference (failure table, nested runs, DBAL 3 emulated nesting, wiring) is in
[Usage](docs/USAGE.md).

Also exported: `TransientFailureClassifier`, `ConnectionRepairer` (the same logic as
`nowo-tech/doctrine-deadlock-retry-bundle` 3.0) and `ConflictCodeResolver` (1.10).

## Write units and the 409 lock-conflict answer (1.3.0)

```php
// Programmatic: replayed up to 3 times (full jitter) after a deadlock, lock wait timeout or serialization failure, outermost unit only.
$writeUnit->run(fn (int $attempt) => $runner->run(fn () => $tasks->get($id)->moveTo($column)));
```

```php
#[AsWriteUnit]                       // controller: replayed with freshly resolved arguments
public function move(Task $task): Response { /* ... */ }
```

With `NowoTransactionDoctrineBundle` registered, a write unit that still conflicts answers **409** (or 503 with
`conflict_response.status: 503`) with `Retry-After`: JSON `{"error": "concurrency_conflict", "code": "CONFLICT_001",
"message": …}` or a branded *Try again* page (UiKit's error page when installed). When part of the unit already
committed, or anything was committed earlier in the same request (request commit watermark, 1.6.0), the code is
`CONFLICT_002` and there is no `Retry-After`. Every answer also carries `X-Conflict-Code: CONFLICT_001|CONFLICT_002`
(1.6.0; clients key on it or on `code`, never on `error`) ([Concurrency](docs/CONCURRENCY.md)). Hosts and bundles map their own exceptions with
`LockConflictExceptionInterface` or a `ConflictExceptionMapperInterface` service, and shape their own answers with a
`ConflictResponseFormatterInterface` service, never by route name. See [Usage](docs/USAGE.md#write-units-130) and
[Configuration](docs/CONFIGURATION.md#symfony-bundle).

## Framework-free conflict types (1.10)

`ConflictCode`, `TransientFailureKind`, `ConcurrencyConflict` and `LockConflictExceptionInterface` now live in
[`nowo-tech/transaction-contract`](https://github.com/nowo-tech/transaction-contract) 1.1 (no Symfony, no Doctrine),
so a bundle's domain / contract layer passes REQ-ARCH-002 (Deptrac). The old `Nowo\TransactionDoctrine\…` names are
deprecated (removed in 2.0): three are aliases of the contract types (same class, `catch` and `instanceof` match both
ways), and `ConflictCode` keeps its helpers and converts with `toContract()` / `fromContract()`. Migration for
bundles: `use Nowo\TransactionContract\…` for the four types, `ConflictCodeResolver::forRequest()` / `forFailure()`
for the code of a failure. See [Usage](docs/USAGE.md#framework-free-conflict-types-110) and
[Upgrading](docs/UPGRADING.md#to-1100).

## Logging (optional)

Pass a PSR-3 logger as the last argument; the default is a `NullLogger`, so nothing changes when you do not:

```php
$runner = DoctrineTransactionRunner::forHostEntityManager($entityManager, $managerRegistry, 'default', 1, $logger);
// forEntityManager($ownManager, $managerRegistry, 'my_bundle', 1, $logger) takes the logger in the same place
$repairer = new ConnectionRepairer(null, $logger);
```

| Event | Level |
| --- | --- |
| Operation failed, classified transient (deadlock, lock wait, ...) | notice |
| Operation failed, not transient | debug |
| Levels rolled back to the entry level | notice |
| Rollback failed, connection closed (session state lost) | warning |
| Entity manager closed and reset through the registry | notice |
| Entity manager cleared after a storage failure, no registry given | notice |
| Entity manager cleared after a domain failure | debug |

Context is structured and safe: exception class and code, nesting levels, action, manager name. SQL, parameters and
exception messages are never logged. A logger that throws is ignored, and the runner stays stateless, so one instance
is safe in a long-running worker.

- Do not log to the **same database connection** the runner uses (for example a Monolog Doctrine handler on the
  default connection): the line is written while that connection's transaction is failing or being rolled back, so
  it is lost. Log to a file, a stream or another connection.
- `exception_code` is the caught exception's `getCode()`: the driver / SQLSTATE code for a storage failure, but for a
  domain exception it is whatever your application set (often `0`). Do not read it as a database error code.

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Concurrency (REQ-RUNTIME-003)](docs/CONCURRENCY.md)
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
- **PHP (lines):** **100%** of `src/` (567/567 lines, 77/77 methods; CI gate on PHP 8.2; locally `make test-coverage`).
- **TS/JS:** N/A
- **Python:** N/A

## License

MIT. See [LICENSE](LICENSE).
