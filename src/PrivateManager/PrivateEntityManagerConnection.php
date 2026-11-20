<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\NativeServer;
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
 * Audit WD-2: a connection released with its transaction still open (DBAL's close() from ConnectionRepairer,
 * DoctrineTransactionRunner or WorkerConnectionHygiene, a lost connection) is rolled back by the server, and so is a
 * transaction whose rollback to a savepoint failed (MySQL / MariaDB after a deadlock): both tell the listener that
 * everything pending is gone (null).
 *
 * Works with DBAL 3 and 4 (audit WD-3), as Dbal\CommitTrackingConnection does: `exec(): int` and `void` transaction
 * methods are valid against both; on DBAL 3 the driver wraps it in Dbal3\PrivateManagerCommitResultConnection, which
 * hands the driver's commit() result back to DBAL's wrapper.
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

    /** Whether a driver-level transaction is open on this connection. */
    private bool $open = false;

    /**
     * PostgreSQL only: a statement of the open transaction failed, so the server answers its COMMIT with a ROLLBACK that
     * the driver reports as a success, until a rollback to a savepoint taken before the failure (audit WD-R1).
     */
    private bool $aborted = false;

    /** Whether the server is PostgreSQL (read from the native connection the first time it matters). */
    private ?bool $postgres = null;

    public function __construct(
        Connection $connection,
        private readonly TransactionLevelListenerInterface $listener,
        private readonly ?string $ownSavepointPrefix = null,
    ) {
        parent::__construct($connection);
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->open    = true; // @igor-ignore — state of this driver connection, not of a request
        $this->aborted = false; // @igor-ignore — state of this driver connection, not of a request
    }

    public function prepare(string $sql): Statement
    {
        return new PrivateEntityManagerStatement(parent::prepare($sql), $this);
    }

    public function query(string $sql): Result
    {
        try {
            return parent::query($sql);
        } catch (Throwable $exception) {
            $this->failed();

            throw $exception;
        }
    }

    /**
     * A statement of this connection failed (PrivateEntityManagerStatement calls it too). On PostgreSQL a failure inside
     * a transaction aborts it: the COMMIT that follows is a ROLLBACK (audit WD-R1).
     */
    public function failed(): void
    {
        if ($this->open && ($this->postgres ??= NativeServer::isPostgres($this))) { // @igor-ignore — fact about this driver connection
            $this->aborted = true; // @igor-ignore — state of this driver connection, not of a request
        }
    }

    public function commit(): void
    {
        $this->trackCommit(parent::commit(...));
    }

    /**
     * Runs the given driver COMMIT and tells the listener how it ended. Dbal3\PrivateManagerCommitResultConnection
     * calls it with the driver's own commit(), to hand DBAL 3's wrapper the driver's result.
     *
     * @param callable(): mixed $commit
     *
     * @return mixed What $commit returned (DBAL 3: the driver's bool; DBAL 4: null)
     */
    public function trackCommit(callable $commit): mixed
    {
        $aborted       = $this->aborted;
        $this->aborted = false; // @igor-ignore — state of this driver connection, not of a request
        try {
            $result = $commit();
        } catch (Throwable $exception) {
            // A refused COMMIT ends the transaction: what it held is gone.
            $this->open = false; // @igor-ignore — state of this driver connection, not of a request
            $this->listener->connectionRolledBack(1);

            throw $exception;
        }

        $this->open = false; // @igor-ignore — state of this driver connection, not of a request
        if ($aborted) {
            // PostgreSQL answered this COMMIT with a ROLLBACK (a statement had failed): nothing of it is left.
            $this->listener->connectionRolledBack(1);

            return $result;
        }

        $this->listener->connectionCommitted();

        return $result;
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->open    = false; // @igor-ignore — state of this driver connection, not of a request
            $this->aborted = false; // @igor-ignore — state of this driver connection, not of a request
            $this->listener->connectionRolledBack(1);
        }
    }

    public function exec(string $sql): int
    {
        $savepointRollback = preg_match(self::SAVEPOINT_ROLLBACK, $sql, $match) === 1;

        try {
            $result = (int) parent::exec($sql);
        } catch (Throwable $exception) {
            $this->failed();
            if ($savepointRollback) {
                // Audit WD-2: the rollback to the savepoint failed (MySQL / MariaDB after a deadlock rolled the whole
                // transaction back): nothing of it is left.
                $this->listener->connectionRolledBack(null);
            }

            throw $exception;
        }

        if ($savepointRollback) {
            $this->aborted = false; // @igor-ignore — the savepoint was taken before the failure: the transaction lives on
        }

        if ($savepointRollback && !$this->isOwn($match[1])) {
            $this->listener->connectionRolledBack(preg_match(self::NESTED, $match[1], $level) === 1 ? (int) $level[1] : null);
        } elseif (preg_match(self::SAVEPOINT_RELEASE, $sql, $match) === 1 && preg_match(self::NESTED, $match[1], $level) === 1) {
            $this->listener->savepointReleased((int) $level[1]);
        }

        return $result;
    }

    /** Audit WD-2: a connection released with its transaction open (closed, lost): the server rolls it back. */
    public function __destruct()
    {
        if ($this->open) {
            $this->listener->connectionRolledBack(null);
        }
    }

    private function isOwn(string $savepoint): bool
    {
        return $this->ownSavepointPrefix !== null && $this->ownSavepointPrefix !== ''
            && str_starts_with(strtolower($savepoint), strtolower($this->ownSavepointPrefix));
    }
}
