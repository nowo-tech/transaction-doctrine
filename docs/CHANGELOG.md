# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Target 1.1.0.

### Added

- **DBAL 3 support**: `doctrine/dbal ^3.8.2 || ^4.0`. The code uses no DBAL 4-only API; CI runs the unit and the
  real-database suites on DBAL 4 + ORM 3, DBAL 3 + ORM 3 and DBAL 3 + ORM 2. New real-database tests pin the behaviour
  the repair and the classifier rely on, on both majors: after a deadlock in a nested transaction with savepoints the
  failed `ROLLBACK TO SAVEPOINT` raises 1305 and leaves the nesting level up until the repair closes the connection;
  a lone 1305 is not transient; the ORM's failed rollback carries the `DeadlockException` in its chain.

### Changed

- **A domain failure no longer resets a shared entity manager** (ChatKit audit QD-M1: resetting the host manager on
  a domain refusal detached the host's entities silently). The contract ("nothing of the failed operation is left
  pending") is unchanged; what the runner does to the manager now depends on the failure:
  - storage failure (a DBAL / driver error in the chain) or a manager the ORM closed: closed and reset through the
    registry, or cleared without one (as in 1.0.0);
  - domain failure after the operation changed or persisted something, or flushed inside the runner's transaction:
    cleared, same instance, never reset;
  - domain failure that left nothing pending (a plain refusal): the manager is left untouched, so the host's entities
    stay managed.

## [1.0.0] - 2026-11-15

### Added

- `DoctrineTransactionRunner` (`forConnection()`, `forEntityManager()`): a one-attempt
  `Nowo\TransactionContract\TransactionRunnerInterface` for plain Doctrine. After any failure it rolls back to the
  entry level and closes the connection only when that rollback fails (session variables and session-level locks
  survive ordinary failures), and leaves no pending changes in the entity manager (closed and reset through the
  registry, or cleared). Transient failures become `TransientStorageFailure`.
- `TransientFailureClassifier` / `TransientFailureKind`: 1213, 1205, MariaDB 1020, SQLSTATE 40001 / 40P01 / 55P03, DBAL
  `RetryableException`; a 1305 lost savepoint only through the deadlock it carries.
- `ConnectionRepairer`.
- Real-database tests (MySQL 8.4, MariaDB 11.4, PostgreSQL 16) and their CI job.

Requires `nowo-tech/transaction-contract ^1.0` and `doctrine/dbal ^4.0`; `doctrine/orm ^3.0` and `doctrine/persistence` are optional.

[Unreleased]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/nowo-tech/transaction-doctrine/releases/tag/v1.0.0
