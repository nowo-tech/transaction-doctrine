<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal;

use Doctrine\DBAL\Driver\Connection;
use PDO;
use Throwable;

use function is_object;

/**
 * What a driver connection's native handle says about the server (1.9). Shared by the driver middlewares that follow
 * how the server ends a transaction (CommitTrackingConnection, PrivateManager\PrivateEntityManagerConnection).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NativeServer
{
    /**
     * Whether the server is PostgreSQL (PDO pgsql or the pgsql extension). On PostgreSQL a statement that fails inside a
     * transaction aborts it: its COMMIT is answered with a ROLLBACK, which the drivers report as a success (audit WD-1).
     * A driver without a native connection counts as not PostgreSQL.
     */
    public static function isPostgres(Connection $connection): bool
    {
        try {
            $native = $connection->getNativeConnection();

            return ($native instanceof PDO && $native->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql')
                || (is_object($native) && $native::class === \PgSql\Connection::class);
        } catch (Throwable) {
            return false; // unknown server: a failed statement does not abort the transaction
        }
    }
}
