<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use SensitiveParameter;

/**
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CommitTrackingDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly CommitLedger $ledger)
    {
        parent::__construct($driver);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        return new CommitTrackingConnection(parent::connect($params), $this->ledger);
    }
}
