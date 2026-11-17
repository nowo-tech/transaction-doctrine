# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/transaction-doctrine`  
**Last audited**: 2026-11-17 (1.3.0 write units)

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

## Symfony bundle (spec [002-write-units](../002-write-units/spec.md))

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Symfony/NowoTransactionDoctrineBundle.php` | Symfony bundle | FR-SF-001 |
| `Symfony/EventListener/WriteUnitControllerListener.php` | Symfony bundle | FR-SF-002 |
| `Symfony/Messenger/WriteUnitMiddleware.php` | Symfony bundle | FR-SF-003 |
| `Symfony/Messenger/UnreplayableDispatch.php` | Symfony bundle | FR-SF-003 |
| `Symfony/EventListener/ConcurrencyConflictResponder.php` | Symfony bundle | FR-SF-004 |
| `Symfony/Conflict/ConflictResponsePolicy.php` | Symfony bundle | FR-SF-005 |
| `Symfony/Conflict/ConflictResponseContext.php` | Symfony bundle | FR-SF-005 |
| `Symfony/Conflict/ConflictExceptionMapperInterface.php` | Symfony bundle | FR-SF-005 |
| `Symfony/Conflict/ConflictResponseFormatterInterface.php` | Symfony bundle | FR-SF-005 |
| `Symfony/Conflict/TransientFailureConflictMapper.php` | Symfony bundle | FR-SF-005 |
| `Symfony/EventListener/WorkerConnectionHygiene.php` | Symfony bundle | FR-SF-006 |
| `../templates/conflict.html.twig`, `../templates/conflict_ui_kit.html.twig`, `../translations/*` | Symfony bundle | FR-SF-007 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Runner | 2 | 2 |
| Repair | 1 | 1 |
| Classification | 2 | 2 |
| Observability | 1 | 1 |
| Write units | 7 | 7 |
| Symfony bundle | 11 | 11 |
| **Total production sources** | **24** | **24** |
