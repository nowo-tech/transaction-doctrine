<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal\Dbal3;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingConnection;

/**
 * DBAL 3 only (audit UE-CF-1): hands the driver's commit() result back to DBAL's wrapper, so
 * `Doctrine\DBAL\Connection::commit()` keeps returning the driver's `true` with commit tracking on (instead of
 * `null`: CommitTrackingConnection::commit() is declared `void` to stay valid against DBAL 4). Everything else goes
 * through the CommitTrackingConnection it wraps; the commit itself is still recorded by it.
 *
 * Loaded only when the installed DBAL's driver Connection::commit() has no return type (DBAL 3): its untyped
 * commit() would not be compatible with DBAL 4. That is why it lives outside src/ (static analysis and coverage run
 * against DBAL 4); the DBAL 3 CI legs test it.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CommitResultConnection extends AbstractConnectionMiddleware
{
    public function __construct(private readonly Connection $driverConnection, private readonly CommitTrackingConnection $tracking)
    {
        parent::__construct($tracking);
    }

    /**
     * @return bool What the driver's commit() returned
     */
    public function commit()
    {
        return $this->tracking->trackCommit(fn (): mixed => $this->driverConnection->commit());
    }
}
