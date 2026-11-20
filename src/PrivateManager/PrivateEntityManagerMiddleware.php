<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use ReflectionMethod;

/**
 * DBAL middleware of a private entity manager (1.9): wraps the source connection's driver so the manager hears how the
 * transactions of that connection end (TransactionLevelListenerInterface). Tag it `doctrine.middleware` for the
 * source manager's connection (PrivateEntityManagerPass does).
 *
 * DBAL 4 only (supported() tells): DBAL 3's driver connection declares `exec(): int` and untyped transaction methods,
 * which the connection wrapper cannot override for both versions. On DBAL 3 the private manager is not told about
 * rollbacks and is only replaced when closed, on another connection, or at the next boundary.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class PrivateEntityManagerMiddleware implements Middleware
{
    /**
     * @param TransactionLevelListenerInterface $listener The private manager
     * @param string|null $ownSavepointPrefix Savepoints whose name starts with this (case-insensitive) belong to the
     *                                        bundle itself: their rollback never makes the manager stale
     */
    public function __construct(
        private TransactionLevelListenerInterface $listener,
        private ?string $ownSavepointPrefix = null,
    ) {
    }

    /** Whether the installed DBAL lets the middleware hear rollbacks (DBAL 4). */
    public static function supported(): bool
    {
        return (string) (new ReflectionMethod(Connection::class, 'rollBack'))->getReturnType() === 'void';
    }

    public function wrap(Driver $driver): Driver
    {
        return new PrivateEntityManagerDriver($driver, $this->listener, $this->ownSavepointPrefix);
    }
}
