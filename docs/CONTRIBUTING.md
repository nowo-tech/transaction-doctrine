# Contributing

This package is small on purpose: one runner and the two helpers it needs (classifier, repairer), on top of
`nowo-tech/transaction-contract`. No Symfony.

## Table of contents

- [Getting started](#getting-started)
- [Quality gates](#quality-gates)
- [Rules that matter](#rules-that-matter)
- [Commits and pull requests](#commits-and-pull-requests)
- [Cursor Agent](#cursor-agent)

## Getting started

With Docker (recommended):

```bash
make up                # build the dev container and install dependencies
make setup-hooks       # git hooks: style + PHPStan before commit, strip AI co-author trailers (REQ-MAKE-006)
make qa                # cs-check + phpstan + test
make test-with-db      # real MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16 suite (containers, no host ports)
make release-check     # full pre-release chain (REQ-MAKE-002)
make down
```

Without Docker (PHP 8.2+ and Composer on the host):

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
- `composer phpstan` — level 8, empty `ignoreErrors`, FrankenPHP classic + worker rulesets
  (`nowo-tech/phpstan-frankenphp`).
- `composer igor` — Igor worker-state audit.
- `composer test` / `composer test-coverage` — PHPUnit (`tests/Unit`, `tests/Integration/Database`); 100% of lines and methods (CI enforces it); the Database suite in CI may not skip.
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

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat → Submit with Ctrl + Enter.
   Enter inserts a newline; Ctrl/⌘+Enter submits the prompt.
2. **Queue follow-ups while the agent is running** — in your user `settings.json` (Linux
   `~/.config/Cursor/User/settings.json`, Windows `%APPDATA%\Cursor\User\settings.json`, macOS
   `~/Library/Application Support/Cursor/User/settings.json`):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts the running agent turn as soon as the follow-up is sent.

Repository rules for assistants live in `.cursor/rules/`; GitHub Spec Kit skills in `.cursor/skills/speckit-*`
(see [SPEC-KIT.md](SPEC-KIT.md)).
