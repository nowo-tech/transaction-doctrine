<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

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

    public function connect(array $params): Connection
    {
        return new PrivateEntityManagerConnection(parent::connect($params), $this->listener, $this->ownSavepointPrefix);
    }
}
