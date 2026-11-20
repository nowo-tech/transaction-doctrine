<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal\Dbal3;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerConnection;

/**
 * DBAL 3 only (1.9, audit WD-3): hands the driver's commit() result back to DBAL's wrapper through a private entity
 * manager's connection (PrivateEntityManagerConnection::commit() is declared `void` to stay valid against DBAL 4), so
 * `Doctrine\DBAL\Connection::commit()` keeps returning the driver's `true`, and so does CommitResultConnection when it
 * wraps this one. Everything else goes through the PrivateEntityManagerConnection it wraps, which still tells the
 * listener how the commit ended. Lives outside src/ for the same reason as CommitResultConnection.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PrivateManagerCommitResultConnection extends AbstractConnectionMiddleware
{
    public function __construct(private readonly Connection $driverConnection, private readonly PrivateEntityManagerConnection $tracking)
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
