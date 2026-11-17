<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

/**
 * Counts the durable writes of the process: every successful COMMIT, and every successful write statement run outside
 * a transaction (an implicit, auto-commit commit), as seen by Dbal\CommitTrackingMiddleware. A write unit reads the
 * count before and after a failed attempt: when it moved, part of the attempt is already committed and replaying it
 * would write twice, so the unit gives up at once.
 *
 * A monotonic counter of the PHP process (one FrankenPHP worker thread or Messenger consumer runs one unit at a time):
 * it holds no request, user or tenant data and needs no reset; only differences are ever read.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CommitLedger
{
    private int $writes = 0;

    public function record(): void
    {
        ++$this->writes;
    }

    public function count(): int
    {
        return $this->writes;
    }
}
