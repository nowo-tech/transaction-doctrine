<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use mysqli;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\OutermostTransactions;
use PDO;
use Throwable;

use function in_array;
use function is_object;
use function preg_match;
use function preg_replace;
use function rtrim;
use function str_contains;

/**
 * One driver connection: knows whether a real (driver-level) transaction is open, and records a successful COMMIT,
 * or a successful write statement outside a transaction, in the ledger. Also counted: any SQL string with several
 * statements, `EXPLAIN ANALYZE`, and on MySQL / MariaDB the statements that commit implicitly inside a transaction
 * (DDL, TRUNCATE, LOCK TABLES, GRANT…). Limits (documented in USAGE): a SELECT calling a writing function, and writes
 * through the native PDO / mysqli handle, are not seen. Savepoints are plain SQL inside the open
 * transaction, so a nested commit is never counted; only the outermost COMMIT is. Its only state is that flag, which
 * belongs to the driver connection (a closed connection is replaced by a new instance).
 *
 * Since 1.9 it also tells the ledger how each outermost transaction of this connection ends (CommitLedger::mark() /
 * outcome()): BEGIN opens one, COMMIT commits it, ROLLBACK or a refused COMMIT rolls it back, and a connection that goes
 * away with a transaction open (closed, lost) rolls it back too, as the server does. Audit WD: a COMMIT after a failed
 * statement on PostgreSQL is a rollback (the server's answer, reported as a success), a MySQL / MariaDB deadlock rolls
 * the transaction back and an implicit-commit statement commits it at once, and a connection lost during COMMIT leaves
 * the outcome unknown.
 *
 * Works with DBAL 3 and 4: the overridden signatures are valid against both (DBAL 3's untyped transaction methods
 * accept a `void` override; their boolean result was never read by DBAL's wrapper except as commit()'s own return).
 * On DBAL 3 the driver middleware wraps it in Dbal3\CommitResultConnection, which hands the driver's commit() result
 * back (since 1.7, audit UE-CF-1: `Connection::commit()` returned `null` instead of `true` with tracking on).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CommitTrackingConnection extends AbstractConnectionMiddleware
{
    /** Statements that never write: anything else run outside a transaction counts as a durable write. */
    private const READ_ONLY = '/^(?:SELECT|SHOW|SET|EXPLAIN|DESCRIBE|DESC|PRAGMA|WITH|VALUES|TABLE)\b/i';

    /**
     * A `WITH` that names a write anywhere (PostgreSQL data-modifying CTEs, MySQL / MariaDB `WITH … UPDATE` /
     * `DELETE`): counted. Conservative: an identifier or literal spelled like one of the keywords also counts.
     */
    private const WRITING_WITH = '/^WITH\b.*\b(?:INSERT|UPDATE|DELETE|MERGE)\b/is';

    /** EXPLAIN ANALYZE executes the statement it explains (PostgreSQL, MariaDB ANALYZE): a write. */
    private const EXPLAIN_ANALYZE = '/^EXPLAIN\s*(?:ANALY[SZ]E\b|\([^)]*\bANALY[SZ]E\b)/i';

    /** Leading block and line comments, skipped before matching the first keyword. */
    private const LEADING_COMMENTS = '/^(?:\s|\(|\/\*.*?\*\/|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$))*/s';

    /** MySQL / MariaDB statements that commit implicitly, even inside a transaction. */
    private const IMPLICIT_COMMIT = '/^(?:CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK|UNLOCK|GRANT|REVOKE|BEGIN|START\s+TRANSACTION|ANALYZE|OPTIMIZE|REPAIR|INSTALL|UNINSTALL|FLUSH|LOAD\s+INDEX|CACHE\s+INDEX|SET\s+(?:PASSWORD|(?:SESSION\s+|@@(?:SESSION\.)?)?AUTOCOMMIT))\b/i';

    /** A rollback to a savepoint: on PostgreSQL it ends the "aborted" state of the transaction (audit WD-1). */
    private const SAVEPOINT_ROLLBACK = '/^ROLLBACK\s+TO\s+(?:SAVEPOINT\s+)?[A-Za-z0-9_"]+\s*;?\s*$/i';

    /** Messages of a connection that went away (driver exceptions have no common class or code for it). */
    private const CONNECTION_LOST = '/gone away|lost connection|server closed the connection|no connection to the server|SSL SYSCALL error|terminating connection|connection reset|broken pipe/i';

    /** SQLSTATE of a lost connection: class 08, and PostgreSQL's administrator / crash shutdown. */
    private const CONNECTION_LOST_STATE = '/^(?:08|57P0[123])/';

    /** MySQL / MariaDB error codes of a lost connection (gone away, lost during query, disconnected by the server). */
    private const CONNECTION_LOST_CODES = [2006, 2013, 4031];

    /** MySQL / MariaDB ER_LOCK_DEADLOCK: the server has rolled the whole transaction back. */
    private const MYSQL_DEADLOCK = 1213;

    private const MYSQL_DEADLOCK_MESSAGE = '/\b1213\b.*Deadlock found/i';

    private bool $inTransaction = false;

    /**
     * PostgreSQL only: a statement of the open transaction failed, so the server will answer its COMMIT with a ROLLBACK
     * (which PDO and pgsql report as a success) until a rollback to a savepoint taken before the failure (audit WD-1).
     */
    private bool $aborted = false;

    /** Whether the server is PostgreSQL (read from the native connection the first time it matters). */
    private ?bool $postgres = null;

    /** Whether the server is MySQL / MariaDB (read from the native connection the first time it matters). */
    private ?bool $mysql = null;

    /** The outermost transactions of this connection in the ledger (resolved at the first BEGIN). */
    private ?OutermostTransactions $transactions = null;

    public function __construct(Connection $connection, private readonly CommitLedger $ledger)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new CommitTrackingStatement(parent::prepare($sql), $this, $sql);
    }

    public function query(string $sql): Result
    {
        try {
            $result = parent::query($sql);
        } catch (Throwable $failed) {
            $this->failed($failed);

            throw $failed;
        }

        $this->executed($sql);

        return $result;
    }

    public function exec(string $sql): int
    {
        try {
            $affected = (int) parent::exec($sql);
        } catch (Throwable $failed) {
            $this->failed($failed);

            throw $failed;
        }

        $this->executed($sql);

        return $affected;
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->inTransaction = true; // @igor-ignore — state of this driver connection, not of a request
        $this->aborted       = false; // @igor-ignore — state of this driver connection, not of a request
        $this->transactions()?->begin();
    }

    public function commit(): void
    {
        $this->trackCommit(parent::commit(...));
    }

    /**
     * Runs the given driver COMMIT and records it once it succeeded. Dbal3\CommitResultConnection calls it with the
     * driver's own commit(), to hand DBAL 3's wrapper the driver's result (audit UE-CF-1).
     *
     * @param callable(): mixed $commit
     *
     * @return mixed What $commit returned (DBAL 3: the driver's bool; DBAL 4: null)
     */
    public function trackCommit(callable $commit): mixed
    {
        // Audit TA2-1: a COMMIT the server refuses (a deferred constraint, PostgreSQL 40001 at commit) still ends the
        // transaction; a flag left true would stop counting the autocommit writes that follow.
        // Audit WD-7: a connection lost during COMMIT leaves the outcome unknown (the server may have committed).
        $aborted = $this->aborted;
        try {
            $result = $commit();
        } catch (Throwable $refused) {
            self::isConnectionLoss($refused) ? $this->transactions?->lost() : $this->transactions?->rolledBack();

            throw $refused;
        } finally {
            $this->inTransaction = false; // @igor-ignore — state of this driver connection, not of a request
            $this->aborted       = false; // @igor-ignore — state of this driver connection, not of a request
        }

        if ($aborted) {
            // Audit WD-1: PostgreSQL answered this COMMIT with a ROLLBACK (a statement had failed): nothing committed.
            $this->transactions?->rolledBack();

            return $result;
        }

        $this->transactions?->committed();
        $this->ledger->record();

        return $result;
    }

    public function rollBack(): void
    {
        $this->inTransaction = false; // @igor-ignore — state of this driver connection, not of a request
        $this->aborted       = false; // @igor-ignore — state of this driver connection, not of a request
        $this->transactions?->rolledBack();
        parent::rollBack();
    }

    /**
     * A statement of this connection failed (CommitTrackingStatement calls it too). On PostgreSQL a failure inside a
     * transaction aborts it: the COMMIT that follows is a ROLLBACK (audit WD-1). On MySQL / MariaDB a deadlock rolls
     * the whole transaction back at once: it is over, and what follows auto-commits until a new BEGIN.
     */
    public function failed(Throwable $failure): void
    {
        if (!$this->inTransaction) {
            return;
        }

        if ($this->isPostgres()) {
            $this->aborted = true; // @igor-ignore — state of this driver connection, not of a request
        } elseif (($failure->getCode() === self::MYSQL_DEADLOCK || preg_match(self::MYSQL_DEADLOCK_MESSAGE, $failure->getMessage()) === 1) && $this->isMySql()) {
            $this->inTransaction = false; // @igor-ignore — state of this driver connection, not of a request
            $this->transactions?->rolledBack();
        }
    }

    /** Whether a driver failure means the connection went away (lost, killed, server shut down). */
    public static function isConnectionLoss(Throwable $failure): bool
    {
        if ($failure instanceof DriverException) {
            if (preg_match(self::CONNECTION_LOST_STATE, (string) $failure->getSQLState()) === 1 || in_array($failure->getCode(), self::CONNECTION_LOST_CODES, true)) {
                return true;
            }
        }

        return preg_match(self::CONNECTION_LOST, $failure->getMessage()) === 1;
    }

    /** A connection released with its transaction open (closed, lost): the server rolls that transaction back. */
    public function __destruct()
    {
        $this->transactions?->rolledBack();
    }

    /** This connection's entry in the ledger; null when the driver has no native connection object (a resource). */
    private function transactions(): ?OutermostTransactions
    {
        if (!$this->transactions instanceof OutermostTransactions) {
            try {
                $native = $this->getNativeConnection();
            } catch (Throwable) {
                return null; // a driver without a native connection: the outcomes of its transactions stay unknown
            }

            if (is_object($native)) {
                $this->transactions = $this->ledger->transactions($native); // @igor-ignore — this driver connection's entry in the ledger
            }
        }

        return $this->transactions;
    }

    /** A statement of this connection ran successfully. */
    public function executed(string $sql): void
    {
        $statement = (string) preg_replace(self::LEADING_COMMENTS, '', $sql, 1);

        if ($this->inTransaction && preg_match(self::IMPLICIT_COMMIT, $statement) === 1 && $this->isMySql()) {
            // Audit WD-7: MySQL / MariaDB committed the open transaction; what follows auto-commits until a new BEGIN.
            $this->inTransaction = false; // @igor-ignore — state of this driver connection, not of a request
            $this->transactions?->committed();
            $this->ledger->record();

            return;
        }

        if ($this->inTransaction && $this->aborted && preg_match(self::SAVEPOINT_ROLLBACK, $statement) === 1) {
            $this->aborted = false; // @igor-ignore — the savepoint was taken before the failure: the transaction lives on
        }

        if ($this->isMultiStatement($sql)
            || (!$this->inTransaction && (preg_match(self::READ_ONLY, $statement) !== 1 || preg_match(self::EXPLAIN_ANALYZE, $statement) === 1
                || preg_match(self::WRITING_WITH, $statement) === 1))) {
            $this->ledger->record();
        }
    }

    /** Several statements in one string (a `;` before the trailing whitespace): counted, whatever they are. */
    private function isMultiStatement(string $sql): bool
    {
        return str_contains(rtrim($sql, " \t\n\r;"), ';');
    }

    private function isPostgres(): bool
    {
        return $this->postgres ??= NativeServer::isPostgres($this); // @igor-ignore — fact about this driver connection, not a request
    }

    private function isMySql(): bool
    {
        if ($this->mysql === null) {
            try {
                $native = $this->getNativeConnection();
                $mysql  = $native instanceof mysqli || ($native instanceof PDO && $native->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
            } catch (Throwable) {
                $mysql = true; // unknown server: count (a false positive only cancels a replay)
            }

            $this->mysql = $mysql; // @igor-ignore — fact about this driver connection, not a request
        }

        return $this->mysql;
    }
}
