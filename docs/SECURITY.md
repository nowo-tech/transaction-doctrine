# Security

Security posture of `nowo-tech/transaction-doctrine`.

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
