# Contributing

This package is small on purpose: one runner and the two helpers it needs (classifier, repairer), on top of
`nowo-tech/transaction-contract`. No Symfony.

## Getting started

```bash
composer install
composer qa          # cs-check + phpstan + test
```

The real-database suite runs when `TD_MYSQL_URL`, `TD_MARIADB_URL` and `TD_POSTGRES_URL` point at MySQL 8.4,
MariaDB 11.4 and PostgreSQL 16 (for example `mysql://root:root@127.0.0.1:3306/ddrb`).

## Quality gates

CI also runs DBAL 3 + ORM 3 and DBAL 3 + ORM 2 legs for the unit and real-database suites: a change must work on
every supported DBAL / ORM major.


- `composer cs-check` / `composer cs-fix` — PHP-CS-Fixer.
- `composer phpstan` — level 8, empty `ignoreErrors`.
- `composer test` — PHPUnit; 100% of lines and methods (CI enforces it); the Database suite in CI may not skip.
- `composer rector-dry` — Rector, no pending changes.
- `composer validate --strict`.

## Rules that matter

- After a failure the runner must leave the connection at its entry nesting level, closing it only when a rollback
  fails, and the entity manager with no pending changes. Every change to the failure path needs a real-database test.
- The classifier is shared in spirit with `nowo-tech/doctrine-deadlock-retry-bundle`: keep both in sync.
- It must work in FrankenPHP worker mode with kernel reset off: no per-call state in services.

## Commits and pull requests

- Conventional commit subjects (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`).
- No AI co-author trailers of any kind (REQ-GIT-001).
- One topic per pull request, with the changelog entry in the same pull request.
- `main` is protected: pull request plus green CI, no force-push, no deletion (REQ-GIT-002).
