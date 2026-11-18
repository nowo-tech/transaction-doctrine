# Configuration

## Table of contents

- [Library (no Symfony)](#library-no-symfony)
- [Symfony bundle](#symfony-bundle)
- [Services and tags](#services-and-tags)
- [Keeping 503](#keeping-503)

## Library (no Symfony)

The library reads no environment variables. Everything is passed in code:

| Where | Option | Default | Meaning |
|-------|--------|---------|---------|
| `forConnection()` / `forEntityManager()` | `$retryAfterSeconds` | `1` | Hint carried by `TransientStorageFailure::retryAfterSeconds()` (`null`: no hint) |
| `DoctrineTransactionRunner::forEntityManager()` | `$registry`, `$managerName` | `null` | Reset a closed manager through the registry; without one the manager is cleared |
| `ConnectionRepairer::__construct()` | `$emulatedNesting` | `null` (detect) | Force DBAL 3 emulated-nesting handling on or off |
| `WriteUnitPolicy::__construct()` | `$maxAttempts` | `3` | Attempts of a write unit, the first one included |
| `WriteUnitPolicy::__construct()` | `$floorMs`, `$baseMs`, `$capMs` | `20`, `100`, `200` | Full-jitter pause: uniform between the floor and `min(cap, base × 2^(attempt − 1))` ms |
| `WriteUnitPolicy::__construct()` | `$replayKinds` | deadlock, lock wait timeout | `TransientFailureKind` cases (or their values) a write unit replays |
| `WriteUnitPolicy::__construct()` | `$retryAfterSeconds` | `1` | Hint of the final `ConcurrencyConflict` |

The test suite reads `TD_MYSQL_URL`, `TD_MARIADB_URL` and `TD_POSTGRES_URL` (real-database suite only); `make
test-with-db` sets them for the Docker containers.

## Symfony bundle

Since 1.3.0, register `Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle` in `config/bundles.php` (see
[Installation](INSTALLATION.md)). Full reference with the defaults:

```yaml
# config/packages/nowo_transaction_doctrine.yaml
nowo_transaction_doctrine:
    # Attempts of a write unit, the first one included (1 = no replay; at most 10).
    max_attempts: 3
    # Full-jitter pause between two attempts: uniform between floor_ms and min(cap_ms, base_ms × 2^(attempt − 1)).
    backoff:
        floor_ms: 20
        base_ms: 100
        cap_ms: 200
    # Transient kinds a write unit replays: deadlock, lock_wait_timeout, serialization_failure, retryable.
    replay_on: [deadlock, lock_wait_timeout]
    # Run controllers marked #[AsWriteUnit] as write units.
    controller_attribute: true
    # Route names that run as write units (exact; "*" is an explicit wildcard): controllers you cannot annotate.
    # A bundle's route also needs that bundle's ConflictResponseFormatterInterface (see Usage).
    controller_routes: []
    # Count commits through a DBAL middleware (tag doctrine.middleware): a failed attempt that already committed is
    # never replayed (no double write); it answers the conflict at once.
    commit_detection: true
    # Register nowo_transaction_doctrine.write_unit_middleware (add it to your buses yourself, see Usage).
    messenger_middleware: true
    # Reset closed entity managers per request; repair connections after a DBAL / ORM failure.
    worker_hygiene: true
    conflict_response:
        enabled: true
        # 409 (the platform default since 1.3.0) or 503 (the 1.2 behaviour of ChatKit, Wiki and TimeTrack).
        status: 409
        # Retry-After seconds (also the hint of ConcurrencyConflict).
        retry_after: 1
        # Twig template of the HTML answer. Variables: status_code, retry_url, retry_after (null after a commit), ui_kit, partial.
        template: '@NowoTransactionDoctrine/conflict.html.twig'
        # The "error" code of the JSON answer {"error": ..., "message": ...}.
        json_error: concurrency_conflict
        # Path prefixes always answered in JSON (XHR, JSON bodies, Accept: application/json and _format json too).
        json_path_prefixes: ['/api/']
        # Also answer any transient storage failure that reaches the kernel, not only write-unit conflicts.
        map_transient_failures: false
```

| Key | Default | Notes |
|-----|---------|-------|
| `max_attempts` | `3` | 1–10 |
| `backoff.floor_ms` / `base_ms` / `cap_ms` | `20` / `100` / `200` | ≥ 0 |
| `replay_on` | `[deadlock, lock_wait_timeout]` | `serialization_failure` (PostgreSQL 40001, MariaDB 1020) and `retryable` (any other DBAL `RetryableException`) are opt-in |
| `controller_attribute` | `true` | `#[AsWriteUnit]` on a controller class or action |
| `controller_routes` | `[]` | Exact route names; `*` is an explicit wildcard (`'*_app_invoice_pay'`) |
| `commit_detection` | `true` | Registers `CommitTrackingMiddleware` (tag `doctrine.middleware`, all connections) and a `CommitLedger` |
| `messenger_middleware` | `true` | Only registered when `symfony/messenger` is installed |
| `worker_hygiene` | `true` | kernel.request / exception / terminate and `WorkerMessageFailedEvent` |
| `conflict_response.enabled` | `true` | `false` removes the responder; the policy service stays (bundles read it) |
| `conflict_response.status` | `409` | `409` or `503` only |
| `conflict_response.retry_after` | `1` | ≥ 0 |
| `conflict_response.template` | `@NowoTransactionDoctrine/conflict.html.twig` | Or override the file in `templates/bundles/NowoTransactionDoctrineBundle/` |
| `conflict_response.json_error` | `concurrency_conflict` | Stable JSON `error` key; `<json_error>_partial` (no `Retry-After`) when part of the unit committed. The `code` key (`CONFLICT_001` / `CONFLICT_002`, 1.5.0) is fixed |
| `conflict_response.json_path_prefixes` | `['/api/']` | Empty entries are ignored |
| `conflict_response.map_transient_failures` | `false` | Runs before the bundles' own mappers: turning it on replaces their JSON shapes with this one. Also maps a lost savepoint with no deadlock behind it (`CONFLICT_002`, 1.5.0) |

Texts: translation domain `nowo_transaction_doctrine` (`conflict.title`, `conflict.lead`, `conflict.hint`,
`conflict.retry`, and `conflict.partial.*` for a conflict after a commit), shipped in English, Spanish, French and German; override them in your own
`translations/nowo_transaction_doctrine.<locale>.yaml`.

## Services and tags

| Service id (public) | Class / alias | Purpose |
|---------------------|---------------|---------|
| `nowo_transaction_doctrine.write_unit` | `WriteUnit`, alias `WriteUnitInterface` | Programmatic write units |
| `nowo_transaction_doctrine.conflict_response_policy` | `ConflictResponsePolicy` | Status + `Retry-After` every bundle reads |
| `nowo_transaction_doctrine.write_unit_middleware` | `WriteUnitMiddleware` | Messenger middleware (add it to a bus) |
| `nowo_transaction_doctrine.state_repairer` (private) | `DoctrineStateRepairer` | Manager reset, rollback, ping |
| `nowo_transaction_doctrine.commit_ledger` (private) | `CommitLedger` | Durable writes counted by the DBAL middleware |
| `nowo_transaction_doctrine.commit_tracking_middleware` (private, `doctrine.middleware`) | `CommitTrackingMiddleware` | Feeds the ledger |

| Tag (autoconfigured) | Interface | Purpose |
|----------------------|-----------|---------|
| `nowo_transaction_doctrine.conflict_exception_mapper` | `ConflictExceptionMapperInterface` | Map your own exceptions to the conflict answer |
| `nowo_transaction_doctrine.conflict_response_formatter` | `ConflictResponseFormatterInterface` | Shape the answer of your own requests (by tag priority) |

Parameters: `nowo_transaction_doctrine.conflict_response.status` and
`nowo_transaction_doctrine.conflict_response.retry_after`. Logger channel: `transaction_doctrine`.

## Keeping 503

1.3.0 answers a lock conflict with **409 Conflict** (owner platform decision: one lock-conflict status, 409 +
`Retry-After`). A host whose clients rely on 503 keeps it with:

```yaml
nowo_transaction_doctrine:
    conflict_response:
        status: 503
```

Bundles that read `ConflictResponsePolicy` (ChatKit, Wiki and TimeTrack from their next minor) follow the same
setting, so the whole application answers one status.
