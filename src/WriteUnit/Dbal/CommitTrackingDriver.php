<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\Dbal3\CommitResultConnection;
use ReflectionMethod;
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
        $tracking = new CommitTrackingConnection($connection = parent::connect($params), $this->ledger);

        return self::driverCommitReturnsResult() ? new CommitResultConnection($connection, $tracking) : $tracking;
    }

    /**
     * Whether the installed DBAL's driver Connection::commit() returns a result (DBAL 3: untyped, documented `bool`,
     * which DBAL's wrapper Connection::commit() returns) or nothing (DBAL 4: `void`).
     */
    public static function driverCommitReturnsResult(): bool
    {
        return !(new ReflectionMethod(DriverConnection::class, 'commit'))->hasReturnType();
    }
}
