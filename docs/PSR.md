# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/transaction-doctrine` (`library`, Doctrine DBAL adapter)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.

## Table of contents

- [Baseline (always)](#baseline-always)
- [Interface / contract PSRs](#interface--contract-psrs)
- [Summary](#summary)

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` PSR-4 map for `src/` and `tests/`. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | Optional trailing `?LoggerInterface` on `DoctrineTransactionRunner::forConnection()` / `forEntityManager()` and `ConnectionRepairer` (default `NullLogger`, REQ-OBS-001); structured context only. Levels in the README. |
| PSR-6 / PSR-16 Cache | **N/A** | No cache. |
| PSR-7 / PSR-17 / PSR-18 HTTP | **N/A** | No HTTP. |
| PSR-11 Container | **N/A** | Named constructors; no container. |
| PSR-14 Event dispatcher | **N/A** | Uses Doctrine's own event manager for the per-run observer; no package events. |
| PSR-15 Middleware | **N/A** | No HTTP pipeline. |
| PSR-20 Clock | **N/A** | No time-dependent logic. |

## Summary

- **Adopted beyond baseline:** PSR-3 (optional logger).

---

_REQ-CS-007 evaluation date: 2026-11-16._
