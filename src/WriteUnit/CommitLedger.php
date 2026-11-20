<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Doctrine\DBAL\Connection;
use LogicException;
use WeakMap;

/**
 * Counts the durable writes of the process: every successful COMMIT, and every successful write statement run outside
 * a transaction (an implicit, auto-commit commit), as seen by Dbal\CommitTrackingMiddleware. A write unit reads the
 * count before and after a failed attempt: when it moved, part of the attempt is already committed and replaying it
 * would write twice, so the unit gives up at once.
 *
 * A monotonic counter of the PHP process (one FrankenPHP worker thread or Messenger consumer runs one unit at a time):
 * it holds no request, user or tenant data and needs no reset; only differences are ever read.
 *
 * Since 1.9 it also keeps, per database connection, the outermost transactions and how they ended (mark() /
 * outcome()): a per-connection commit epoch that tells whether the very transaction a write belonged to committed,
 * where count() only tells that something, anywhere, committed. Held in a WeakMap keyed by the native connection
 * (PDO / mysqli …): a closed connection's entry goes with it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CommitLedger
{
    private int $writes = 0;

    /** @var WeakMap<object, OutermostTransactions> Per native connection */
    private WeakMap $connections;

    public function __construct()
    {
        $this->connections = new WeakMap();
    }

    public function record(): void
    {
        ++$this->writes;
    }

    public function count(): int
    {
        return $this->writes;
    }

    /**
     * The outermost transactions of a native connection (Dbal\CommitTrackingMiddleware feeds them).
     *
     * @internal
     */
    public function transactions(object $nativeConnection): OutermostTransactions
    {
        return $this->connections[$nativeConnection] ??= new OutermostTransactions(); // @igor-ignore — per-connection bookkeeping, weakly keyed
    }

    /**
     * Marks the outermost transaction currently open on the connection (1.9). Outside a transaction the mark stands for
     * an auto-committed write.
     *
     * @throws LogicException When the connection is inside a transaction that commit tracking did not see (the
     *                        connection does not go through Dbal\CommitTrackingMiddleware fed by this ledger)
     */
    public function mark(Connection $connection): TransactionMark
    {
        if (!$connection->isTransactionActive()) {
            return new TransactionMark();
        }

        $transactions = $this->connections[$connection->getNativeConnection()] ?? null;
        $serial       = $transactions?->current();
        if (!$transactions instanceof OutermostTransactions || $serial === null) {
            throw new LogicException('CommitLedger::mark() needs the connection to go through CommitTrackingMiddleware fed by this ledger (the bundle\'s commit_detection).');
        }

        return new TransactionMark($transactions, $serial);
    }

    /**
     * How the marked transaction ended (1.9): true when it committed (or the mark was taken outside a transaction),
     * false when it was rolled back, refused at commit or lost with its connection, null while it is still open (or
     * when it is older than the last OutermostTransactions::KEEP transactions of that connection: unknown).
     */
    public function outcome(TransactionMark $mark): ?bool
    {
        if (!$mark->transactions instanceof OutermostTransactions || $mark->serial === null) {
            return true;
        }

        return $mark->transactions->outcome($mark->serial);
    }
}
