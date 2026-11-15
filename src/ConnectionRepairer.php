<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Throwable;

use function version_compare;

/**
 * Brings a DBAL connection back to a known transaction nesting level after a failure.
 *
 * DBAL 4 lowers the nesting level only after a successful ROLLBACK TO SAVEPOINT. After a MySQL / MariaDB deadlock in
 * a nested transaction the server has dropped every savepoint, the rollback fails with 1305 and the level stays
 * above 0 until Connection::close(), which also makes the server discard whatever is left of the transaction.
 * The repairer therefore rolls back first and closes only when that fails: closing loses session state (variables,
 * session-level locks), so it is the last resort.
 *
 * DBAL 3 without savepoints emulates nesting: an inner rollBack() issues no SQL and only marks the transaction
 * rollback-only, a flag that survives Connection::close() and makes the next commit fail. With emulated nesting the
 * repairer never closes a nested leftover: it rolls back level by level (the level-1 ROLLBACK clears the flag), and
 * when even that real ROLLBACK fails it closes and then begins and rolls back once, to clear the flag.
 *
 * Stateless: safe as a shared service in a long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ConnectionRepairer
{
    public const ACTION_NONE        = 'none';
    public const ACTION_ROLLED_BACK = 'rolled_back';
    public const ACTION_CLOSED      = 'closed';

    /**
     * @param bool|null $emulatedNesting Whether nested transactions are emulated (DBAL 3 without savepoints); null = detect per connection
     */
    public function __construct(
        private ?bool $emulatedNesting = null,
    ) {
    }

    /**
     * Repairs the connection after a failed operation that started at $entryLevel.
     *
     * The levels the operation left open are rolled back down to $entryLevel; the connection is closed only when a
     * rollback fails (after a nested deadlock the server already dropped the savepoints, so ROLLBACK TO SAVEPOINT
     * fails with 1305). A plain ROLLBACK of the outermost level never fails that way, so session state (variables,
     * GET_LOCK / advisory locks) survives ordinary failures.
     *
     * @return string One of the ACTION_* constants
     */
    public function afterFailure(Connection $connection, int $entryLevel): string
    {
        if ($connection->getTransactionNestingLevel() <= $entryLevel) {
            return self::ACTION_NONE;
        }

        return $this->rollBackTo($connection, $entryLevel);
    }

    /**
     * Repairs a connection left with an open transaction between two requests: a single level is rolled back when
     * possible, anything deeper (or a failed rollback) closes the connection.
     *
     * @param int $baseline Expected level between requests (1 for a connection with auto-commit disabled)
     *
     * @return string One of the ACTION_* constants
     */
    public function betweenRequests(Connection $connection, int $baseline = 0): string
    {
        $level = $connection->getTransactionNestingLevel();

        if ($level <= $baseline) {
            return self::ACTION_NONE;
        }

        if ($level === $baseline + 1 || $this->emulatesNesting($connection)) {
            return $this->rollBackTo($connection, $baseline);
        }

        $connection->close();

        return self::ACTION_CLOSED;
    }

    private function rollBackTo(Connection $connection, int $level): string
    {
        try {
            // Bounded: with auto-commit disabled DBAL opens a new transaction after the outermost rollback.
            for ($remaining = $connection->getTransactionNestingLevel() - $level; $remaining > 0; --$remaining) {
                $connection->rollBack();
            }

            return self::ACTION_ROLLED_BACK;
        } catch (Throwable) {
            $connection->close();

            if ($this->emulatesNesting($connection)) {
                $this->clearRollbackOnly($connection);
            }

            return self::ACTION_CLOSED;
        }
    }

    private function emulatesNesting(Connection $connection): bool
    {
        if ($this->emulatedNesting !== null) {
            return $this->emulatedNesting;
        }

        // Only DBAL 3 can nest without savepoints; DBAL 4's getter is deprecated (and always true), so it is not called.
        return version_compare((string) InstalledVersions::getVersion('doctrine/dbal'), '4.0.0', '<')
            && !$connection->getNestTransactionsWithSavepoints();
    }

    private function clearRollbackOnly(Connection $connection): void
    {
        try {
            $connection->beginTransaction();
            $connection->rollBack();
        } catch (Throwable) {
            // The server is gone: the next transaction reconnects; nothing more can be done here.
        }
    }
}
