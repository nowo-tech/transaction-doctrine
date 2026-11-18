# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.6.0] - 2026-11-18](#160---2026-11-18)
- [[1.5.0] - 2026-11-18](#150---2026-11-18)
- [[1.4.0] - 2026-11-18](#140---2026-11-18)
- [[1.3.1] - 2026-11-17](#131---2026-11-17)
- [[1.3.0] - 2026-11-17](#130---2026-11-17)
- [[1.2.0] - 2026-11-17](#120---2026-11-17)
- [[1.1.0] - 2026-11-15](#110---2026-11-15)
- [[1.0.0] - 2026-11-15](#100---2026-11-15)

## [Unreleased]

### Fixed

- **`forHostEntityManager()` keeps the cause of a refusal (audit TA-3 final, Info 1):** when the pending-changes check
  calls `UnitOfWork::computeChangeSets()` and it throws (a `prePersist` listener or callback of an entity reached by
  cascade persist, for example), the `LogicException` (`DoctrineTransactionRunner::HOST_PENDING_MESSAGE`) now carries
  that exception as `getPrevious()`. The message is unchanged; a refusal for scheduled work has no previous, as
  before.
- **`ConflictCode::forFailure()` answers `CONFLICT_002` for more committed work (audits TA-3 / TJ, same rules as
  `nowo-tech/doctrine-deadlock-retry-bundle` 3.2.0):** it used to walk only `getPrevious()` and let the first
  `ConcurrencyConflict` decide. It now also answers `CONFLICT_002` (no `Retry-After`) when:
  - a `ConcurrencyConflict` with `committed: true` is anywhere in the failure: behind an uncommitted one, or (with
    `symfony/messenger`) inside a wrapper's `getWrappedExceptions()`, at any depth;
  - (with `symfony/messenger`) a `HandlerFailedException` whose envelope carries a `HandledStamp` (another handler of
    the message already succeeded), or any `DelayedMessageHandlingException` (the outer dispatch, and its transaction,
    already committed).
  Known over-approximation (conservative, same as DDRB 3.2.0, audit TA4-2): a `HandledStamp`, or a
  `DelayedMessageHandlingException` under an outer transaction that rolled back, answers `CONFLICT_002` even when
  nothing committed (see the accepted residual risks in [Concurrency](CONCURRENCY.md#accepted-residual-risk)).
  The walk visits each throwable once and at most 256 of them, never queues more than it may still visit, and
  ignores anything a custom wrapper returns that is not a `Throwable` (a cyclic or broken custom wrapper cannot hang
  a worker or turn the 409 into a 500). Without
  `symfony/messenger` the Messenger rules are skipped (`interface_exists()` guard). `ConflictCode::forRequest()` and
  the 409 responder inherit the change.
- **`forEntityManager()` is documented as bundle-owned only (audit TG delta follow-up):** the factory docblock, the
  README logging example, the Usage example and the Configuration table used or described it with the host's
  `default` manager, or said only a closed manager is reset. After any storage failure it closes and resets the
  manager through the registry even when it is still open; for the host's manager use `forHostEntityManager()`. No
  behaviour change.

## [1.6.0] - 2026-11-18

### Added

- **Request commit watermark (audit TH X-1, REQ-RUNTIME-003 rule 6 request-level detection):** a conflict after a
  commit earlier in the same request (the host, another bundle, an earlier unit) answers `CONFLICT_002` without
  `Retry-After` instead of `CONFLICT_001` + `Retry-After`, which invited a blind retry of committed work.
  - New `Nowo\TransactionDoctrine\WriteUnit\CommitMark` (a watermark on the `CommitLedger`, `committedSince()`).
  - New `Nowo\TransactionDoctrine\Symfony\EventListener\RequestCommitWatermark`: with `commit_detection` on, takes the
    mark at `kernel.request` (priority 4096) on the main request, in the request attribute
    `CommitMark::REQUEST_ATTRIBUTE`; sub-requests carry the main request's mark.
  - `ConflictCode::forRequest(Request $request, Throwable $failure)` for bundles answering in their own shape, and an
    optional `?CommitMark $since` argument on `ConflictCode::forFailure()`.
  - The responder (answer and log) uses `ConflictCode::forRequest()`.
  - The `CommitLedger` service is aliased as `Nowo\TransactionDoctrine\WriteUnit\CommitLedger` (with
    `commit_detection`), so a Messenger handler or command can take its own mark.
- **`X-Conflict-Code` header (audit TH X-2, platform convention):** every responder answer carries
  `X-Conflict-Code: CONFLICT_001|CONFLICT_002` (only those two values), whatever built the body.
  `ConflictResponsePolicy::headersFor(ConflictCode $code, ?int $hint)` (the header, plus `Retry-After` only for
  `CONFLICT_001`), `ConflictResponsePolicy::httpExceptionFor()` and the `ConflictResponsePolicy::CODE_HEADER` constant
  let bundles send it too. Documented: cross-origin APIs add it to `Access-Control-Expose-Headers`; clients key on
  `code` or the header, never on `error`.
- **`DoctrineTransactionRunner::forHostEntityManager()` (audit TG delta, REQ-RUNTIME-003 rule 2):** a runner for the
  host application's entity manager. After a failure it never closes, clears or resets an open manager; it replaces a
  closed one through the registry and detaches only what the failed run brought in (entities that entered the
  identity map or were scheduled for insertion during the run). `forEntityManager()` is unchanged and now documented
  as the factory for bundle-owned managers.
  - The detach never cascades (`cascade: detach`) into an entity the host managed before the run (audit TA3-1).
  - The brought entities are removed from the host entities' initialized collections, and a host to-one pointing at
    one gets its original value back, so the host's next flush cannot re-insert them (audit TA3-2).
  - A notice is logged when host entities still have pending changes after the failure (audit TA3-3).
  - `run()` refuses a host manager that already has pending changes (scheduled insertions, updates or deletions,
    after `UnitOfWork::computeChangeSets()`) with a `LogicException`
    (`DoctrineTransactionRunner::HOST_PENDING_MESSAGE`) before opening a transaction: a failure after a flush inside
    the run would otherwise lose the host's work silently (audit TA3-6). A to-one restored on a host entity with no
    original value is set to `null` (TA3-7).

### Changed

- Commit detection: `WITH`, `VALUES` and `TABLE` statements outside a transaction are reads and no longer count as a
  commit; a `WITH` naming `INSERT`, `UPDATE`, `DELETE` or `MERGE` (a data-modifying CTE) still counts (audit TA3-4).
  Fewer `CONFLICT_002` answers and refused replays after a plain read.

### Fixed

- **DBAL 3: a driver error whose code is a SQLSTATE string no longer crashes the classifier (audit TA3-5; affected
  1.1.0 to 1.5.x on DBAL 3).** DBAL 3's `Doctrine\DBAL\Driver\PDO\PDOException` is both a `PDOException` and a driver
  exception, and its `getCode()` is the SQLSTATE (`'40001'`, `'23505'`). `TransientFailureClassifier` passed it to an
  `int` parameter under `strict_types`: a `TypeError` replaced the original failure, so the runner's repair and
  cleanup did not finish and a write unit did not replay. A PostgreSQL serialization failure at COMMIT, for example,
  answered a 500 instead of being replayed. The classifier now reads the vendor code from `errorInfo[1]` and the
  SQLSTATE from `errorInfo[0]` (or `getSQLState()`), and treats any non-int code as no vendor code. `WriteUnit`
  classifies through it, so it is fixed too. DBAL 4 was not affected. No GHSA (owner decision 2026-11-18).

## [1.5.0] - 2026-11-18

### Added

- **Conflict codes (REQ-RUNTIME-003 rule 6):** every conflict answer carries `CONFLICT_001` (nothing committed, with
  `Retry-After`) or `CONFLICT_002` (part of the unit committed, no `Retry-After`).
  - New `Nowo\TransactionDoctrine\ConflictCode` backed enum (`RetrySafe`, `Committed`; constants `RETRY_SAFE`,
    `COMMITTED`) with `ConflictCode::forFailure()` for bundles that answer in their own shape.
  - JSON answers gain a `code` key next to the unchanged `error` and `message` keys.
  - The HTML page (plain, Twig and UiKit variants) carries `data-conflict-code` on its `data-testid="concurrency-conflict"`
    element; the template receives `conflict_code`.
  - `ConflictResponseContext::$code`, so a `ConflictResponseFormatterInterface` receives the code.
  - The responder's log context gains `code`.
- `TransientFailureClassifier::isCommitOutcomeUnknown()`: a lost savepoint (MySQL / MariaDB 1305 about a SAVEPOINT,
  PostgreSQL 3B001) with no transient failure in its chain, answered `CONFLICT_002` by the opt-in
  `map_transient_failures` mapper. A 1305 about a missing function, unique (constraint) violations and lost
  connections are never mapped.
- `docs/CONCURRENCY.md` (write paths, classification, codes, host options, residual risk) and a USAGE section on how
  bundles comply with REQ-RUNTIME-003 using this package.

### Changed

- **Serialization failures are replayed by default** (owner decision 2026-11-18): the default `replay_on` and
  `WriteUnitPolicy` kinds now include `serialization_failure` (PostgreSQL 40001, MariaDB 1020), new constant
  `WriteUnitPolicy::DEFAULT_REPLAY_KINDS`. A write unit replays them and, once the attempts are exhausted, throws
  `ConcurrencyConflict`, answered 409 `CONFLICT_001` + `Retry-After` instead of a 500. An explicit `replay_on` list
  keeps the 1.4 behaviour (see UPGRADING).

### Fixed

- **Duplicate autocommit writes after a failed COMMIT (audit TA2-1, affects 1.3.0–1.4.0; no GHSA, owner decision
  2026-11-18):** when the server refused a COMMIT (a deferred constraint, or a PostgreSQL
  serialization failure at commit time), `CommitTrackingConnection` kept believing a transaction was open, so the
  autocommit writes that followed on that connection were no longer counted by `CommitLedger`. A write unit that met a
  conflict after such a write was then replayed instead of answering `committed: true`, and repeated the write. The
  flag is now reset in a `finally`; a refused COMMIT is still not counted as a commit. Tested on PostgreSQL 16.
- **Audit TA2-2:** a lost savepoint (1305) is recognised on servers with localized messages (`lc_messages` such as
  `es_ES` or `de_DE`): the classifier matches the untranslated `SAVEPOINT` keyword where the server puts it, no longer
  the English "does not exist". A 1305 about a missing `FUNCTION` stays unmapped in every language.
- **Audit TA-6:** a reset-between-attempts service or retry listener that opened a transaction (or persisted an entity)
  and then threw left the connection one nesting level up after the write unit gave up; the unit now discards it
  before throwing `ConcurrencyConflict`. Tested on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16.

## [1.4.0] - 2026-11-18

### Added

- **Reset hook between write-unit attempts** (SYMFONY-BEACON spec 241 T020): request memos that keep entities are
  emptied before a replay, so it no longer runs on detached entities (`ORMInvalidArgumentException` 500 instead of the
  409, or silently lost changes).
  - `ResetBetweenAttemptsInterface::resetBetweenAttempts()`: autoconfigured (tag
    `nowo_transaction_doctrine.reset_between_attempts`), called in tag priority order after the entity managers were
    cleared or reset and before the next attempt, for `#[AsWriteUnit]`, `controller_routes`, the Messenger middleware
    and the programmatic service. Never before the first attempt nor after the last failure.
  - `WriteUnitRetryEvent` (PSR-14, on `event_dispatcher`), dispatched after the resetters: attempt about to run,
    failure, kind and unit name (route name, message class, or the new optional `$unit` argument of `WriteUnit::run()`).
  - A resetter or retry listener that throws abandons the replay: `ConcurrencyConflict` with the attempt's failure as
    previous and the new `ConcurrencyConflict::$resetFailure` (logged at `error`). So does one that leaves a
    transaction open or entity changes pending (discarded first; `$resetFailure` is a `LogicException`), which would
    otherwise make the replay run nested and return without committing.
  - A `Generator` passed as `$resetters` is read once into a list, so units with several replays work.
  - `WriteUnit` takes two new optional constructor arguments: `iterable $resetters` and a PSR-14 `$dispatcher`.

## [1.3.1] - 2026-11-17

### Fixed

- Missing it/nl/pt translations: the `nowo_transaction_doctrine` catalogue now covers the full house set (en, es, it, fr, pt,
  de, nl), so the conflict message and page no longer fall back to English; a unit test requires every key in every locale.

## [1.3.0] - 2026-11-17

### Added

- **Write units** (Beacon KIT-79, spec 239): `WriteUnit` (`WriteUnitInterface`) replays a unit of work after a lock
  conflict, a deadlock or a lock wait timeout by default (`TransientFailureClassifier` kinds; `serialization_failure` /
  `retryable` opt-in), found in the previous chain or inside a Messenger `HandlerFailedException`. Up to 3 attempts
  with a full-jitter pause (20 ms floor, 100 ms base, 200 ms cap; `WriteUnitPolicy`). Only the outermost unit replays:
  inside an open transaction the work runs once. Between attempts `DoctrineStateRepairer` rolls back leftover levels
  (through `ConnectionRepairer`), resets closed entity managers and clears open ones. The last conflict becomes
  `ConcurrencyConflict`, a contract `TransientStorageFailure` with `attempts`, `kind` and a Retry-After hint.
- `#[AsWriteUnit]` controller attribute and `LockConflictExceptionInterface` marker.
- Optional Symfony bundle `Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle` (config root
  `nowo_transaction_doctrine`; register it in `config/bundles.php`):
  - `WriteUnitControllerListener`: `#[AsWriteUnit]` controllers and `controller_routes` (exact names, explicit `*`
    wildcard) run as write units; on a replay only Doctrine entity arguments are reloaded by identifier (request
    payload DTOs and other arguments keep their first-attempt value) and an entity user is reloaded (403 when gone);
  - `WriteUnitMiddleware` (service `nowo_transaction_doctrine.write_unit_middleware`): consumed messages run as write
    units;
  - `ConcurrencyConflictResponder`: a lock conflict answers **409 + `Retry-After`** (`conflict_response.status`: 409 or
    503; `retry_after`), JSON `{"error": "concurrency_conflict", "message"}` or a branded HTML page with *Try again*
    (`@NowoTransactionDoctrine/conflict.html.twig`, overridable, extends UiKit's error page when installed;
    translations en / es / fr / de);
  - exception-mapping hook `ConflictExceptionMapperInterface` and response hook `ConflictResponseFormatterInterface`
    (autoconfigured tags; no route-name matching; TaskBoard API small ask C2), plus the opt-in
    `map_transient_failures` mapper;
  - `ConflictResponsePolicy` (service `nowo_transaction_doctrine.conflict_response_policy`): the one status and
    `Retry-After` every bundle reads;
  - `WorkerConnectionHygiene` (`worker_hygiene`): closed managers reset per main request; after a DBAL / ORM failure of
    a request (at kernel.terminate) or a consumed message, leftover levels rolled back and connected connections pinged.
- Commit detection (audit SL-M1): `CommitLedger` and the DBAL driver middleware `Dbal\CommitTrackingMiddleware`
  (DBAL 3 and 4) count successful COMMITs and write statements outside a transaction; a failed attempt that already
  committed is never replayed and becomes `ConcurrencyConflict` with `committed: true`. Bundle option
  `commit_detection` (default true, tag `doctrine.middleware`). Rule documented: a unit commits at most once, or is
  idempotent as a whole.
- A conflict after a commit (`committed: true`, audit SL-D-M1) is answered 409 **without `Retry-After`**, with the JSON
  code `concurrency_conflict_partial` and a "part of your change may have been saved; reload before trying again" text
  and *Reload* link (en / es / fr / de, keys `conflict.partial.*`); `ConflictResponseContext` gains `committed` and a
  nullable `retryAfterSeconds`. `WriteUnitMiddleware` wraps it in an `UnrecoverableMessageHandlingException`, so the
  transport does not retry the message.
- Commit detection also counts multi-statement SQL strings, `EXPLAIN ANALYZE` (also inside an `EXPLAIN (…)` option
  list), and MySQL / MariaDB implicit-commit statements inside a transaction, every `SET [SESSION | @@[SESSION.]]
  autocommit` form included; leading `/* … */`, `--` and `#` comments are skipped before matching (SL-D-L1, SL-2-L2).
  A conflict after a commit carries no Retry-After hint (SL-2-L1). The limits are documented in USAGE.
- Write units run once (no replay, no clear) when an open entity manager has pending changes before the first attempt
  (SL-M2); a Messenger wrapper is replayed only when every wrapped exception is a replayable conflict and no handler
  succeeded (SL-M4); a wrapped inner `ConcurrencyConflict` is never replayed again (SL-L1).
- Real-database tests for write units (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): a real deadlock and lock wait timeouts
  replayed, the last one as `ConcurrencyConflict`, the nested-transaction rule and the repairer.

### Changed

- Owner platform decision: one lock-conflict status, **409 + `Retry-After`**. The bundle's default
  `conflict_response.status` is 409; `503` keeps the 1.2 behaviour of ChatKit, Wiki and TimeTrack (see UPGRADING).
- `composer.json`: Symfony, Twig and Messenger packages as dev requirements and suggestions; `branch-alias`
  `dev-main` → `1.3.x-dev`. Runtime requirements are unchanged.
- `composer.json` `conflict`: `symfony/http-kernel <6.4`, `symfony/messenger <6.4`.
- The responder uses a `ConcurrencyConflict`'s own Retry-After hint, and limits the *Try again* referer to http(s).
- Logging stays constant-message with structured context only (class, code, counters, kind, route name, method).

## [1.2.0] - 2026-11-17

### Added

- REQ-OBS-001: optional PSR-3 logging. `DoctrineTransactionRunner::forConnection()` / `forEntityManager()` and `ConnectionRepairer::__construct()` take an optional trailing `LoggerInterface` (default `NullLogger`; BC-safe). Logged with structured context only (exception class and code, levels, action, manager name; never SQL, parameters or exception messages): the failure and its transient classification (notice when transient, debug otherwise), the repair (notice for rolled-back levels, warning when the connection is closed), and the entity manager reset after a storage failure (notice), clear after a storage failure when no registry is given (notice) or clear after a domain failure (debug). A logger that throws never replaces the original failure.
- Do not give the runner a logger that writes to the **same database connection**: the failure is logged while that connection's transaction is failing or being rolled back, so the log row is lost (or the write fails). Use a file, stream or another connection.
- `exception_code` is the caught exception's `getCode()`. For storage failures it is the driver / SQLSTATE code; for a domain exception it is whatever the host application set (often `0`), so do not treat it as a database error code.
- `psr/log ^1.0 || ^2.0 || ^3.0` is now a direct requirement (already pulled in by `doctrine/dbal`).

### Changed

- Repository tooling and documentation aligned with the nowo-tech package requirements (REQ compliance sweep
  2026-11-16); the only `src/` change is the optional logger above, and without a logger the behaviour is unchanged.
- Development: Docker image + `docker-compose.yml` (MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 behind the `db`
  profile), root `Makefile` (`release-check`, `test-with-db`, `setup-hooks`, `igor`, …), git hooks; PHPStan now
  includes the `nowo-tech/phpstan-frankenphp` classic and worker rulesets; Igor worker-state audit (`composer igor`,
  `make igor`, `make release-check`) with `igor-baseline.json` for the per-run event listener (audit SE-TD-L1);
  CI adds Rector, Igor, `composer audit` and a git-history check; tests moved to `tests/Unit/` and `tests/Integration/Database/`.
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

[Unreleased]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.6.0...HEAD
[1.6.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.4.0...v1.5.0
[1.4.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/transaction-doctrine/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nowo-tech/transaction-doctrine/releases/tag/v1.0.0
