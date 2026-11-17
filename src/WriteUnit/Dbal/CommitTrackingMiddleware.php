<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;

/**
 * DBAL driver middleware that reports every durable write to a CommitLedger: a successful COMMIT of the driver
 * connection, and a successful write statement run outside a transaction. Register it on every connection a write
 * unit uses (NowoTransactionDoctrineBundle does, through the `doctrine.middleware` tag, with `commit_detection: true`).
 * Works with DBAL 3 and 4. It runs no SQL of its own.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class CommitTrackingMiddleware implements Middleware
{
    public function __construct(private CommitLedger $ledger)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new CommitTrackingDriver($driver, $this->ledger);
    }
}
