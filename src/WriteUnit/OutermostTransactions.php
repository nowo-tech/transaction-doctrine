<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

/**
 * The outermost (driver-level) transactions of one database connection, as Dbal\CommitTrackingMiddleware sees them
 * (1.9): each BEGIN opens a new TransactionOutcome, and its COMMIT or ROLLBACK (or the connection going away with it
 * open) records how it ended. Savepoints are plain SQL inside the open transaction and never open a new one.
 *
 * Only the open transaction is held here; an ended one lives on in the marks taken in it (audit WD-5: no cap, no
 * history). One instance per native connection, held by CommitLedger in a WeakMap: it holds no request, user or
 * tenant data, so worker mode needs no reset.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class OutermostTransactions
{
    private ?TransactionOutcome $open = null;

    /** A driver-level BEGIN succeeded. */
    public function begin(): void
    {
        $this->open?->end(false); // a BEGIN on top of an unseen end (cannot happen through DBAL): that one is over
        $this->open = new TransactionOutcome(); // @igor-ignore — state of one database connection, not of a request
    }

    /** The open transaction committed. */
    public function committed(): void
    {
        $this->end(true);
    }

    /** The open transaction was rolled back, refused at commit, or lost with its connection before COMMIT. */
    public function rolledBack(): void
    {
        $this->end(false);
    }

    /** The connection was lost during COMMIT: whether the server committed is unknown (audit WD-7). */
    public function lost(): void
    {
        $this->end(null);
    }

    /** The open transaction, or null when none is open. */
    public function current(): ?TransactionOutcome
    {
        return $this->open;
    }

    private function end(?bool $committed): void
    {
        $this->open?->end($committed);
        $this->open = null; // @igor-ignore — state of one database connection, not of a request
    }
}
