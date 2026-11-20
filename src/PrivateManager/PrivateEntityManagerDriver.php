<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingDriver;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\Dbal3\PrivateManagerCommitResultConnection;
use SensitiveParameter;

/**
 * Driver of PrivateEntityManagerMiddleware: every connection it opens tells the listener how its transactions end.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PrivateEntityManagerDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly TransactionLevelListenerInterface $listener,
        private readonly ?string $ownSavepointPrefix = null,
    ) {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): Connection
    {
        $tracking = new PrivateEntityManagerConnection($connection = parent::connect($params), $this->listener, $this->ownSavepointPrefix);

        return CommitTrackingDriver::driverCommitReturnsResult() ? new PrivateManagerCommitResultConnection($connection, $tracking) : $tracking;
    }
}
