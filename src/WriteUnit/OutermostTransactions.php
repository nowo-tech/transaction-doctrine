<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use function array_key_first;
use function count;

/**
 * The outermost (driver-level) transactions of one database connection, as Dbal\CommitTrackingMiddleware sees them
 * (1.9): each BEGIN opens the next serial, and its COMMIT or ROLLBACK (or the connection going away with it open)
 * records how it ended. Savepoints are plain SQL inside the open transaction and never move the serial.
 *
 * Bounded: the outcomes of the last KEEP transactions are kept, older ones are forgotten (an older mark then reads as
 * unknown). One instance per native connection, held by CommitLedger in a WeakMap and by the marks taken on it: it
 * holds no request, user or tenant data, so worker mode needs no reset.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class OutermostTransactions
{
    /** How many ended transactions keep their outcome. */
    public const KEEP = 256;

    private int $serial = 0;

    private bool $open = false;

    /** @var array<int, bool> Ended transactions: serial => committed */
    private array $outcomes = [];

    /** A driver-level BEGIN succeeded. */
    public function begin(): void
    {
        ++$this->serial; // @igor-ignore — state of one database connection, not of a request
        $this->open = true; // @igor-ignore — state of one database connection, not of a request
    }

    /** The open transaction committed. */
    public function committed(): void
    {
        $this->end(true);
    }

    /** The open transaction was rolled back, refused at commit, or lost with its connection. */
    public function rolledBack(): void
    {
        $this->end(false);
    }

    /** The serial of the open transaction, or null when none is open. */
    public function current(): ?int
    {
        return $this->open ? $this->serial : null;
    }

    /**
     * How the transaction with this serial ended: true committed, false rolled back, null still open or forgotten.
     */
    public function outcome(int $serial): ?bool
    {
        return $this->outcomes[$serial] ?? null;
    }

    private function end(bool $committed): void
    {
        if (!$this->open) {
            return;
        }

        $this->open                    = false; // @igor-ignore — state of one database connection, not of a request
        $this->outcomes[$this->serial] = $committed; // @igor-ignore — bounded outcomes of one database connection
        if (count($this->outcomes) > self::KEEP) {
            unset($this->outcomes[array_key_first($this->outcomes)]); // @igor-ignore — bounded outcomes of one database connection
        }
    }
}
