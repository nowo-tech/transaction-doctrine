<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
 * The rollback-only flag (Connection::setRollbackOnly(); with DBAL 3 without savepoints, also every inner rollBack(),
 * which issues no SQL) survives Connection::close() on DBAL 3 and DBAL 4 alike, and makes the next commit on the
 * reopened connection fail. Only a ROLLBACK of the outermost level clears it. With emulated nesting the repairer never
 * closes a nested leftover: it rolls back level by level (the level-1 ROLLBACK clears the flag). Whenever it does
 * close a connection whose transaction was rollback-only (or whose flag could not be read, or with emulated nesting),
 * it then begins and rolls back every level that BEGIN left open (one, or two with auto-commit disabled), to clear the
 * flag (1.7.2: before, only with emulated nesting).
 *
 * Nesting levels are read as `(int)`: DBAL 3 declares getTransactionNestingLevel() without a native return type, so a
 * test double of DBAL 3's Connection returns null (1.7.2).
 *
 * Stateless: safe as a shared service in a long-running worker. An optional PSR-3 logger (default NullLogger) receives
 * the repair actions (notice: levels rolled back; warning: connection closed) with structured context only: levels,
 * action and the failure's class and code. Never SQL, parameters or messages.
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
        private LoggerInterface $logger = new NullLogger(),
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
        if ((int) $connection->getTransactionNestingLevel() <= $entryLevel) {
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
        $level = (int) $connection->getTransactionNestingLevel();

        if ($level <= $baseline) {
            return self::ACTION_NONE;
        }

        if ($level === $baseline + 1 || $this->emulatesNesting($connection)) {
            return $this->rollBackTo($connection, $baseline);
        }

        SafeLog::log($this->logger, 'warning', 'Closing a connection left inside a transaction between requests.', [
            'nesting_level' => $level,
            'baseline'      => $baseline,
            'action'        => self::ACTION_CLOSED,
        ]);
        $rollbackOnly = $this->isRollbackOnly($connection);
        $connection->close();

        if ($rollbackOnly) {
            $this->clearRollbackOnly($connection);
        }

        return self::ACTION_CLOSED;
    }

    private function rollBackTo(Connection $connection, int $level): string
    {
        // Read once, before the try: also the level reported if the rollback fails. The flag is read while a level is
        // still open (isRollbackOnly() throws at level 0, and a failed level-1 rollback already dropped the level).
        $from         = (int) $connection->getTransactionNestingLevel();
        $rollbackOnly = $this->isRollbackOnly($connection);

        try {
            // Bounded: with auto-commit disabled DBAL opens a new transaction after the outermost rollback.
            for ($remaining = $from - $level; $remaining > 0; --$remaining) {
                $connection->rollBack();
            }

            SafeLog::log($this->logger, 'notice', 'Rolled back transaction levels left open by a failure.', [
                'from_level' => $from,
                'to_level'   => $level,
                'action'     => self::ACTION_ROLLED_BACK,
            ]);

            return self::ACTION_ROLLED_BACK;
        } catch (Throwable $failure) {
            SafeLog::log($this->logger, 'warning', 'Rollback failed; closing the connection (session state is lost).', [
                'from_level' => $from,
                'to_level'   => $level,
                'action'     => self::ACTION_CLOSED,
            ] + SafeLog::throwable($failure));
            $connection->close();

            if ($rollbackOnly || $this->emulatesNesting($connection)) {
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

    /**
     * Whether the open transaction is rollback-only; true when the flag cannot be read (clearing it is harmless).
     */
    private function isRollbackOnly(Connection $connection): bool
    {
        try {
            return (bool) $connection->isRollbackOnly();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Clears the rollback-only flag of a closed connection: only a ROLLBACK of the outermost level resets it.
     *
     * With auto-commit disabled (audit VQ-6) the reconnect inside BEGIN makes DBAL open level 1 by itself, so that
     * BEGIN only adds a savepoint (level 2): one ROLLBACK would release the savepoint and keep the flag. Every level
     * open after the BEGIN is therefore rolled back; DBAL then opens level 1 again on its own (auto-commit off).
     */
    private function clearRollbackOnly(Connection $connection): void
    {
        try {
            $connection->beginTransaction();
            for ($level = (int) $connection->getTransactionNestingLevel(); $level > 0; --$level) {
                $connection->rollBack();
            }
        } catch (Throwable $failure) {
            SafeLog::log($this->logger, 'debug', 'Could not clear the rollback-only flag after closing.', SafeLog::throwable($failure));

            // BEGIN reached the driver and failed: DBAL already counts the level. Drop it again (DBAL 4 clears the
            // flag even when the driver's ROLLBACK fails); when the server is gone the next transaction reconnects,
            // and DoctrineTransactionRunner clears a stale flag right after its own outermost BEGIN.
            if ((int) $connection->getTransactionNestingLevel() > 0) {
                try {
                    $connection->rollBack();
                } catch (Throwable) {
                    $connection->close();
                }
            }
        }
    }
}
