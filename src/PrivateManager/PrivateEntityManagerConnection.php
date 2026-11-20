<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

use function preg_match;
use function str_starts_with;
use function strtolower;

/**
 * Driver connection of PrivateEntityManagerMiddleware: tells the listener when the outermost transaction commits or
 * rolls back, and when DBAL's nested savepoints (`DOCTRINE_<level>` / `DOCTRINE2_SAVEPOINT_<level>`) are rolled back or
 * released. A rollback to a savepoint the application named itself has no known level (null: treated as undoing
 * everything pending); one of the bundle's own savepoints (ownSavepointPrefix) is ignored.
 *
 * DBAL 4 signatures (see PrivateEntityManagerMiddleware::supported()).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PrivateEntityManagerConnection extends AbstractConnectionMiddleware
{
    private const SAVEPOINT_ROLLBACK = '/^\s*ROLLBACK\s+(?:TO\s+SAVEPOINT|TRAN(?:SACTION)?)\s+([A-Za-z0-9_]+)\s*;?\s*$/i';

    private const SAVEPOINT_RELEASE = '/^\s*RELEASE\s+SAVEPOINT\s+([A-Za-z0-9_]+)\s*;?\s*$/i';

    private const NESTED = '/^DOCTRINE(?:2_SAVEPOINT)?_?(\d+)$/i';

    public function __construct(
        Connection $connection,
        private readonly TransactionLevelListenerInterface $listener,
        private readonly ?string $ownSavepointPrefix = null,
    ) {
        parent::__construct($connection);
    }

    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $exception) {
            // A refused COMMIT ends the transaction: what it held is gone.
            $this->listener->connectionRolledBack(1);

            throw $exception;
        }

        $this->listener->connectionCommitted();
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->listener->connectionRolledBack(1);
        }
    }

    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        if (preg_match(self::SAVEPOINT_ROLLBACK, $sql, $match) === 1 && !$this->isOwn($match[1])) {
            $this->listener->connectionRolledBack(preg_match(self::NESTED, $match[1], $level) === 1 ? (int) $level[1] : null);
        } elseif (preg_match(self::SAVEPOINT_RELEASE, $sql, $match) === 1 && preg_match(self::NESTED, $match[1], $level) === 1) {
            $this->listener->savepointReleased((int) $level[1]);
        }

        return $result;
    }

    private function isOwn(string $savepoint): bool
    {
        return $this->ownSavepointPrefix !== null && $this->ownSavepointPrefix !== ''
            && str_starts_with(strtolower($savepoint), strtolower($this->ownSavepointPrefix));
    }
}
