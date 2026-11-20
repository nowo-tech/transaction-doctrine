<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

/**
 * Which outermost transaction of a connection a write belongs to (1.9, CommitLedger::mark()): taken right after the
 * write (or when deferring work that must only follow its commit), and read later with CommitLedger::outcome() to
 * know whether exactly that transaction committed. Unlike a CommitMark, other connections and later transactions of
 * the same connection never count: a host transaction rolled back after a bundle released its savepoint reads as
 * rolled back even when unrelated writes commit afterwards (the "phantom" case of an outbox released at the end of
 * the request).
 *
 * Immutable; a mark taken outside a transaction stands for an auto-committed write (always committed). The mark holds
 * the transaction's outcome itself, so it never expires however many transactions follow (audit WD-5).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class TransactionMark
{
    /**
     * @param TransactionOutcome|null $transaction The transaction that was open; null outside a transaction
     *
     * @internal Taken with CommitLedger::mark()
     */
    public function __construct(
        public ?TransactionOutcome $transaction = null,
    ) {
    }

    /** Whether the mark was taken outside any transaction (an auto-committed write). */
    public function isAutoCommit(): bool
    {
        return !$this->transaction instanceof TransactionOutcome;
    }
}
