# Security

Security posture of `nowo-tech/transaction-doctrine`.

## Table of contents

- [Reporting a vulnerability](#reporting-a-vulnerability)
- [Scope and attack surface](#scope-and-attack-surface)
- [Integrity](#integrity)
- [Information disclosure](#information-disclosure)
- [Dependencies](#dependencies)
- [Logging](#logging)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)

## Reporting a vulnerability

Report privately to <hectorfranco@nowo.tech>. Do not open a public issue for an unfixed vulnerability.

## Scope and attack surface

The package runs a caller-provided operation in a Doctrine transaction. It builds no SQL from input: the only
statements it causes are DBAL's own `BEGIN` / `COMMIT` / `ROLLBACK` / savepoint statements. It has no configuration,
no I/O besides the given connection, and no per-call state.

## Integrity

- After a failure no change of the failed operation stays pending in the entity manager, so a later flush, run or
  request (worker mode) never commits half of a failed unit of work.
- A failure whose outcome is unknown (connection lost during the commit) is not reported as transient: retrying it
  could apply the operation twice.
- Closing the connection (only when a rollback fails) releases session-level locks; ordinary failures keep them.

## Information disclosure

`TransientStorageFailure` carries a fixed generic message; the storage error, which can contain SQL and parameters,
stays in `getPrevious()`. Never render the exception string or the previous chain to clients. The classifier reads
error codes only.

## Dependencies

`php`, `doctrine/dbal ^3.8.2 || ^4.0` and `nowo-tech/transaction-contract`; `doctrine/orm` and `doctrine/persistence` are
optional. Development tooling is `require-dev` and excluded from the archive by `.gitattributes`.

## Logging

Logging is optional (PSR-3, REQ-OBS-001; default `NullLogger`). Records carry structured context only: exception
class and code, nesting levels, action, manager name. SQL, parameters and exception messages are never logged, and a
logger that throws never replaces the original failure. Levels are listed in the README. Failures are still rethrown
(transient storage failures as `TransientStorageFailure` with a fixed message). Never log the `previous` chain
verbatim: it can contain SQL and parameters. Do not log to the same database connection the runner uses (the line is
lost inside the failed transaction); `exception_code` of a domain exception is host-defined.

## Release security checklist (12.4.1)

Confirm each item before tagging (a note in the release PR is enough).

| Item | Status for this package |
|------|-------------------------|
| `docs/SECURITY.md` and `.github/SECURITY.md` current | This file + the GitHub policy template |
| `.env` ignored | `.gitignore` ignores `.env`, `.env.local`, `.env.*.local`; the package reads no environment (the test suite reads `TD_*_URL`) |
| No secrets in the repository | Only throwaway local / CI database passwords for test containers |
| Safe recipe / configuration | No Flex recipe, no configuration |
| Input / output | Builds no SQL from input; only DBAL transaction statements |
| Dependencies | `doctrine/dbal ^3.8.2 \|\| ^4.0`, `nowo-tech/transaction-contract`; CI runs `composer audit` |
| Logs | Optional PSR-3 logger, structured context only (no SQL, parameters or messages); storage errors stay in `getPrevious()` |
| Cryptography | Not applicable |
| Permissions / exposure | No services, routes, commands or files |
| Limits / DoS | No retry loop (one attempt); repair is bounded by the nesting level |
| AI security audit (REQ-SEC-004) | `BUNDLES_SECURITY_ANALYSIS.md` Appendix QA (2026-11-15): Pass for 1.0.0 and 1.1.0 |
