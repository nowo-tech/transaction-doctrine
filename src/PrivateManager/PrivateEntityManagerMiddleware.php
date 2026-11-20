<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware of a private entity manager (1.9): wraps the source connection's driver so the manager hears how the
 * transactions of that connection end (TransactionLevelListenerInterface). Tag it `doctrine.middleware` for the
 * source manager's connection (PrivateEntityManagerPass does).
 *
 * Works with DBAL 3 and 4 (audit WD-3). On DBAL 3 with savepoints off, a nested rollBack() runs no SQL (DBAL only
 * marks the transaction rollback-only): the manager hears it when the outermost transaction rolls back.
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

    public function wrap(Driver $driver): Driver
    {
        return new PrivateEntityManagerDriver($driver, $this->listener, $this->ownSavepointPrefix);
    }
}
