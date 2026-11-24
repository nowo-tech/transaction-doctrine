<?php

declare(strict_types=1);

/**
 * Deprecated names of transaction-doctrine < 1.10, kept until 2.0 as class aliases of the framework-free types of
 * nowo-tech/transaction-contract >= 1.1: each old name and its contract name are the same class (or enum, or
 * interface), so `instanceof`, `catch`, type declarations and `===` on enum cases work with either name, both ways.
 *
 * Registered eagerly through Composer's `autoload.files`, not on first use: `catch` and `instanceof` never trigger
 * the autoloader, so `catch (Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict $e)` would miss a thrown
 * Nowo\TransactionContract\ConcurrencyConflict if nothing had loaded the old name before.
 *
 * Nowo\TransactionDoctrine\ConflictCode is not aliased: it keeps its static forFailure() / forRequest() (Doctrine,
 * HttpFoundation, Messenger) and is a deprecated enum of its own; see Nowo\TransactionDoctrine\ConflictCodeResolver.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */

namespace Nowo\TransactionDoctrine;

use Nowo\TransactionContract;

use function class_alias;
use function class_exists;
use function interface_exists;

/* @deprecated since transaction-doctrine 1.10, removed in 2.0: use Nowo\TransactionContract\TransientFailureKind */
if (!class_exists(TransientFailureKind::class, false)) {
    class_alias(TransactionContract\TransientFailureKind::class, TransientFailureKind::class);
}

/* @deprecated since transaction-doctrine 1.10, removed in 2.0: use Nowo\TransactionContract\ConcurrencyConflict */
if (!class_exists(WriteUnit\ConcurrencyConflict::class, false)) {
    class_alias(TransactionContract\ConcurrencyConflict::class, WriteUnit\ConcurrencyConflict::class);
}

/* @deprecated since transaction-doctrine 1.10, removed in 2.0: use Nowo\TransactionContract\LockConflictExceptionInterface */
if (!interface_exists(WriteUnit\LockConflictExceptionInterface::class, false)) {
    class_alias(TransactionContract\LockConflictExceptionInterface::class, WriteUnit\LockConflictExceptionInterface::class);
}
