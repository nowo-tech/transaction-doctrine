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
 * Immutable; a mark taken outside a transaction stands for an auto-committed write (always committed).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class TransactionMark
{
    /**
     * @param OutermostTransactions|null $transactions The connection's transactions; null outside a transaction
     * @param int|null $serial The serial of the transaction that was open; null outside a transaction
     */
    public function __construct(
        public ?OutermostTransactions $transactions = null,
        public ?int $serial = null,
    ) {
    }

    /** Whether the mark was taken outside any transaction (an auto-committed write). */
    public function isAutoCommit(): bool
    {
        return !$this->transactions instanceof OutermostTransactions || $this->serial === null;
    }
}
