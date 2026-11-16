# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/transaction-doctrine`  
**Last audited**: 2026-11-16

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

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Runner | 2 | 2 |
| Repair | 1 | 1 |
| Classification | 2 | 2 |
| **Total production sources** | **5** | **5** |
