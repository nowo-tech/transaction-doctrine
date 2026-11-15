# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
