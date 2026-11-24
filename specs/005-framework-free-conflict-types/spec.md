# Feature Specification: Framework-free conflict types (REQ-ARCH-002 prerequisite)

**Feature Branch**: `feat/framework-free-conflict-types`
**Status**: Active (next minor, 1.10.0)
**Source**: Owner, 2026-11-24: prerequisite of the REQ-ARCH-002 (Deptrac) rollout. Bundles following REQ-RUNTIME-003
rule 6 expose a public `*ConflictException` in their `Domain` / `Contract` layer that names `ConflictCode`,
`TransientFailureKind`, `ConcurrencyConflict` and `LockConflictExceptionInterface`; this package is not framework-free
(`ConflictCode` imports HttpFoundation and Messenger), so Deptrac fails on Domain/Contract → transaction-doctrine.

**Package**: `nowo-tech/transaction-doctrine` (with `nowo-tech/transaction-contract` 1.1.0)

---

## Summary

The four value types move to transaction-contract 1.1 with identical names, values and semantics. This package keeps
the Doctrine / Symfony logic (working out a failure's code, the HTTP answer) and keeps every 1.9 name working until
2.0, with no BC break.

## Requirements

- **FR-001**: `Nowo\TransactionDoctrine\TransientFailureKind`, `…\WriteUnit\ConcurrencyConflict` and
  `…\WriteUnit\LockConflictExceptionInterface` are class aliases of the `Nowo\TransactionContract\…` types, registered
  eagerly (Composer `autoload.files`), so `catch` and `instanceof` match either name in both directions even when the
  old name was never autoloaded. Deprecated, removed in 2.0.
- **FR-002**: `Nowo\TransactionDoctrine\ConflictCode` stays an enum with the same cases, constants, `retrySafe()`,
  `forFailure()` and `forRequest()` (delegating to FR-003), plus `toContract()` and `fromContract()` (accepts either
  enum). Deprecated, removed in 2.0.
- **FR-003**: `ConflictCodeResolver::forFailure(Throwable, ?CommitMark)` / `forRequest(Request, Throwable)` return
  `Nowo\TransactionContract\ConflictCode` with the 1.9 rules of spec 004 (watermark, Messenger wrappers, lost
  savepoints, walk cap). Stateless.
- **FR-004**: `ConflictResponsePolicy::headersFor()` / `httpExceptionFor()` and `ConflictResponseContext` accept either
  enum; `ConflictResponseContext::$code` keeps the deprecated type and `$contractCode` carries the contract case.
- **FR-005**: Internal code names the contract types; write units throw the same class as in 1.9.

## Success Criteria

- **SC-001**: A test proves `catch` and `instanceof` interoperate in both directions for the aliased types, enum case
  identity for `TransientFailureKind`, and conversion plus dual acceptance for `ConflictCode`
  (`tests/Unit/ContractInteropTest.php`).
- **SC-002**: Every 1.9 test passes unchanged; 100% line and method coverage; PHPStan, Rector, PHP-CS-Fixer and Igor
  clean.
