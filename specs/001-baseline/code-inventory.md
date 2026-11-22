# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/transaction-doctrine`  
**Last audited**: 2026-11-22 (1.9.3)

## Runner

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DoctrineTransactionRunner.php` | Runner | FR-RUN-001 |
| `RunObserver.php` | Per-run observer (internal) | FR-RUN-002 |

## Repair

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `ConnectionRepairer.php` | Connection repair | FR-REP-001 |

## Classification

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `TransientFailureClassifier.php` | Transient classifier | FR-CLS-001 |
| `TransientFailureKind.php` | Transient kinds | FR-CLS-002 |
| `ThrowableWalk.php` | Transient classifier | FR-CLS-001 |
| `ConflictCode.php` | Conflict codes (spec [004-conflict-codes](../004-conflict-codes/spec.md)) | 004 FR-001, 004 FR-006 |

## Observability

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `SafeLog.php` | Observability | FR-OBS-001 |

## Write units (spec [002-write-units](../002-write-units/spec.md))

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `WriteUnit/WriteUnit.php` | Write units | FR-WU-001 |
| `WriteUnit/WriteUnitInterface.php` | Write units | FR-WU-001 |
| `WriteUnit/WriteUnitPolicy.php` | Write units | FR-WU-002 |
| `WriteUnit/DoctrineStateRepairer.php` | Write units | FR-WU-003 |
| `WriteUnit/ConcurrencyConflict.php` | Write units | FR-WU-004 |
| `WriteUnit/LockConflictExceptionInterface.php` | Write units | FR-WU-004 |
| `WriteUnit/AsWriteUnit.php` | Write units | FR-WU-004 |
| `WriteUnit/CommitLedger.php` | Write units | FR-WU-005 |
| `WriteUnit/CommitMark.php` | Write units | FR-WU-005, 004 FR-001 |
| `WriteUnit/ResetBetweenAttemptsInterface.php` | Write units (spec [003-replay-reset-hook](../003-replay-reset-hook/spec.md)) | 003 FR-001, 003 FR-002 |
| `WriteUnit/WriteUnitRetryEvent.php` | Write units (spec [003-replay-reset-hook](../003-replay-reset-hook/spec.md)) | 003 FR-002, 003 FR-004 |
| `WriteUnit/AttemptObserver.php` | Write units | FR-WU-003 |
| `WriteUnit/KeptEntitiesRestorer.php` | Write units | FR-WU-003 |
| `WriteUnit/Dbal/CommitTrackingMiddleware.php` | Write units | FR-WU-005 |
| `WriteUnit/Dbal/CommitTrackingDriver.php` | Write units | FR-WU-005 |
| `WriteUnit/Dbal/CommitTrackingConnection.php` | Write units | FR-WU-005 |
| `WriteUnit/Dbal/CommitTrackingStatement.php` | Write units | FR-WU-005 |
| `WriteUnit/TransactionMark.php` | Write units | FR-WU-006 |
| `WriteUnit/TransactionOutcome.php` | Write units | FR-WU-006 |
| `WriteUnit/OutermostTransactions.php` | Write units | FR-WU-006 |
| `WriteUnit/Dbal/NativeServer.php` | Write units | FR-WU-006 |

## Symfony bundle (spec [002-write-units](../002-write-units/spec.md))

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Symfony/NowoTransactionDoctrineBundle.php` | Symfony bundle | FR-SF-001 |
| `Symfony/EventListener/WriteUnitControllerListener.php` | Symfony bundle | FR-SF-002 |
| `Symfony/Messenger/WriteUnitMiddleware.php` | Symfony bundle | FR-SF-003 |
| `Symfony/Messenger/UnreplayableDispatch.php` | Symfony bundle | FR-SF-003 |
| `Symfony/EventListener/ConcurrencyConflictResponder.php` | Symfony bundle | FR-SF-004 |
| `Symfony/EventListener/RequestCommitWatermark.php` | Symfony bundle | FR-SF-004, 004 FR-001 |
| `Symfony/Conflict/ConflictResponsePolicy.php` | Symfony bundle | FR-SF-005 |
| `Symfony/Conflict/ConflictResponseContext.php` | Symfony bundle | FR-SF-005 |
| `Symfony/Conflict/ConflictExceptionMapperInterface.php` | Symfony bundle | FR-SF-005 |
| `Symfony/Conflict/ConflictResponseFormatterInterface.php` | Symfony bundle | FR-SF-005 |
| `Symfony/Conflict/TransientFailureConflictMapper.php` | Symfony bundle | FR-SF-005 |
| `Symfony/EventListener/WorkerConnectionHygiene.php` | Symfony bundle | FR-SF-006 |
| `Symfony/Boundary/UnitOfWorkFrames.php` | Symfony bundle | FR-SF-008 |
| `Symfony/EventListener/UnitOfWorkBoundaryListener.php` | Symfony bundle | FR-SF-008 |
| `../templates/conflict.html.twig`, `../templates/conflict_ui_kit.html.twig`, `../translations/*` | Symfony bundle | FR-SF-007 |

## Private entity manager (spec [002-write-units](../002-write-units/spec.md))

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `HostFlushProbe.php` | Private entity manager and host-flush probe | FR-PM-004 |
| `OrphanRemovals.php` | Private entity manager and host-flush probe | FR-PM-004 |
| `PrivateManager/PrivateEntityManager.php` | Private entity manager and host-flush probe | FR-PM-001 |
| `PrivateManager/PrivateManagerRegistry.php` | Private entity manager and host-flush probe | FR-PM-001 |
| `PrivateManager/PrivateManagerRepositoryFactory.php` | Private entity manager and host-flush probe | FR-PM-001 |
| `PrivateManager/PrivateEntityManagerMiddleware.php` | Private entity manager and host-flush probe | FR-PM-002 |
| `PrivateManager/PrivateEntityManagerDriver.php` | Private entity manager and host-flush probe | FR-PM-002 |
| `PrivateManager/PrivateEntityManagerConnection.php` | Private entity manager and host-flush probe | FR-PM-002 |
| `PrivateManager/PrivateEntityManagerStatement.php` | Private entity manager and host-flush probe | FR-PM-002 |
| `PrivateManager/TransactionLevelListenerInterface.php` | Private entity manager and host-flush probe | FR-PM-002 |
| `PrivateManager/DependencyInjection/PrivateEntityManagerPass.php` | Private entity manager and host-flush probe | FR-PM-003 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Runner | 2 | 2 |
| Repair | 1 | 1 |
| Classification | 4 | 4 |
| Observability | 1 | 1 |
| Write units | 21 | 21 |
| Symfony bundle | 14 | 14 |
| Private entity manager | 11 | 11 |
| **Total production sources** | **54** | **54** |
