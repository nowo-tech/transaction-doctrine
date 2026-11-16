# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.1.0] - 2026-11-15](#110---2026-11-15)
- [[1.0.0] - 2026-11-15](#100---2026-11-15)

## [Unreleased]

### Changed

- No change under `src/`: repository tooling and documentation aligned with the nowo-tech package requirements
  (REQ compliance sweep 2026-11-16).
- Development: Docker image + `docker-compose.yml` (MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 behind the `db`
  profile), root `Makefile` (`release-check`, `test-with-db`, `setup-hooks`, `igor`, …), git hooks; PHPStan now
  includes the `nowo-tech/phpstan-frankenphp` classic and worker rulesets; Igor worker-state audit; CI adds Rector,
  Igor, `composer audit` and a git-history check; tests moved to `tests/Unit/` and `tests/Integration/Database/`.
- `composer.json`: `archive.exclude`, `support.docs`; `branch-alias` `dev-main` → `1.1.x-dev`.

### Documentation

- `docs/INSTALLATION.md`, `CONFIGURATION.md`, `USAGE.md` (the behaviour reference moved there from the README),
  `UPGRADING.md`, `RELEASE.md`, `ENGRAM.md`, `PSR.md`, `SPEC-DRIVEN-DEVELOPMENT.md`, `SPEC-KIT.md`; GitHub Spec Kit
  baseline (`specs/001-baseline/`, 5/5 files mapped); release security checklist (12.4.1) and logging policy in
  `SECURITY.md`; canonical README.
- `.github/`: issue / pull request templates, `CODEOWNERS`, `SECURITY.md`, Copilot instructions, Dependabot,
  release, release-sync, PR-title lint and stale workflows. `.cursor/` rules and Engram MCP config.

## [1.1.0] - 2026-11-15

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
  - domain failure that re-hydrated an entity already managed (`refresh()`, a proxy initialised inside the run):
    cleared;
  - otherwise (a refusal): every entity that entered the identity map during the run is detached, since it may hold
    rows or values the rollback undid (QA-TD11-M1: a row inserted or a value updated through DBAL, then found, stayed
    in the identity map), including `getReference()` proxies and deferred query-cache hydrations that fire no
    `postLoad` (QA-TD11-L4); the host's other entities stay managed. When a detach
    cascades (`cascade: ['detach']`) into a caller's entity, the manager is cleared instead of leaving that entity
    silently detached (QA-TD11-L3).
  The run is watched by a per-run `postFlush` / `postLoad` listener (removed in `finally`) and an identity-map
  snapshot taken when the run starts.
- The pending-change check looks at the scheduled insertions, deletions and collection changes first and calls
  `UnitOfWork::computeChangeSets()` only when there are none; its cost (a scan of the identity map) and side effects
  (cascade-persist runs `prePersist` and may assign generated ids) are documented (QA-TD11-L2).
- A nested domain failure clears the caller's pending changes too: documented in the README, with the rule not to
  continue the same unit of work after catching it (QA-TD11-M2).
- **DBAL 3 emulated nesting** (savepoints off): `ConnectionRepairer` rolls nested leftovers back level by level instead
  of closing, and after a failed real `ROLLBACK` it closes and then begins and rolls back once, because
  `Connection::close()` keeps the rollback-only flag and the next commit would fail (QA-TD11-L1). New optional
  constructor argument `ConnectionRepairer(?bool $emulatedNesting = null)` (null = detect: DBAL 3 without savepoints).

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

[Unreleased]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nowo-tech/transaction-doctrine/releases/tag/v1.0.0
