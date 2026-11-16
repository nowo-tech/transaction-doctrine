# Installation

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Optional packages](#optional-packages)

## Requirements

- PHP **8.2** to **8.5** (`>=8.2 <8.6`).
- `doctrine/dbal` **^3.8.2 || ^4.0**.
- [`nowo-tech/transaction-contract`](https://github.com/nowo-tech/transaction-contract) **^1.0** (installed automatically).

## Install

```bash
composer require nowo-tech/transaction-doctrine
```

## Optional packages

| Package | Needed for |
|---------|------------|
| `doctrine/orm` (2.20+ or 3.x) | `DoctrineTransactionRunner::forEntityManager()` |
| `doctrine/persistence` (^3.1 or ^4.0) | Resetting a closed entity manager through a `ManagerRegistry` |

No Symfony dependency and no bundle class: nothing to register. Next: [Usage](USAGE.md).
