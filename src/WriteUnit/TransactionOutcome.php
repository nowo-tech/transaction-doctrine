<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

/**
 * How one outermost transaction of a connection ended (1.9): created at its BEGIN, held by the connection while the
 * transaction is open and by the TransactionMark objects taken in it. Nothing else keeps it, so there is no cap and no
 * history to trim: a mark's outcome is never forgotten (audit WD-5), and it goes away with the last mark.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransactionOutcome
{
    private bool $ended = false;

    private ?bool $committed = null;

    /**
     * Records how the transaction ended; only the first call counts.
     *
     * @param bool|null $committed true committed, false rolled back, null unknown (the connection was lost at COMMIT)
     */
    public function end(?bool $committed): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended     = true; // @igor-ignore — state of one database transaction, not of a request
        $this->committed = $committed; // @igor-ignore — state of one database transaction, not of a request
    }

    /** Whether the transaction ended (committed, rolled back, or lost with an unknown outcome). */
    public function hasEnded(): bool
    {
        return $this->ended;
    }

    /** true committed, false rolled back, null still open or unknown (hasEnded() tells them apart). */
    public function committed(): ?bool
    {
        return $this->committed;
    }
}
