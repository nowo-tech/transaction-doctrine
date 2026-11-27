# Installation

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Optional packages](#optional-packages)
- [Symfony bundle (optional)](#symfony-bundle-optional)

## Requirements

- PHP **8.2** to **8.5** (`>=8.2 <8.7`).
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
| `doctrine/persistence` (^3.1 or ^4.0) | Resetting a closed entity manager through a `ManagerRegistry`; `WriteUnit` and `DoctrineStateRepairer` |
| `symfony/http-kernel` (6.4, 7.x, 8.x) | The optional `NowoTransactionDoctrineBundle` |
| `symfony/messenger` | `WriteUnitMiddleware` |
| `symfony/clock` | Pausing between write-unit attempts through the clock (testable with `MockClock`) |
| `twig/twig` | The branded HTML conflict page (a plain page is built without Twig) |
| `nowo-tech/ui-kit-bundle` | The conflict page extends UiKit's error page when UiKit is installed |

## Symfony bundle (optional)

The library works without Symfony. Since 1.3.0 an optional bundle wires write units, the 409 conflict responder and
worker connection hygiene from configuration. The package type stays `library`, so Symfony Flex does not register the
bundle by itself: add it to `config/bundles.php`:

```php
return [
    // ...
    Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle::class => ['all' => true],
];
```

It needs DoctrineBundle (the `doctrine` registry service). Everything else (Twig, the translator, Messenger, the
security token storage, the clock, the logger) is used when present. Next: [Configuration](CONFIGURATION.md) and
[Usage](USAGE.md).
