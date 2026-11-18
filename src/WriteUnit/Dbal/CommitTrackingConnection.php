<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use mysqli;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use PDO;
use Throwable;

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
 * Works with DBAL 3 and 4: the overridden signatures are valid against both (DBAL 3's untyped transaction methods
 * accept a `void` override; their boolean result was never read by DBAL's wrapper except as commit()'s own return).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CommitTrackingConnection extends AbstractConnectionMiddleware
{
    /** Statements that never write: anything else run outside a transaction counts as a durable write. */
    private const READ_ONLY = '/^(?:SELECT|SHOW|SET|EXPLAIN|DESCRIBE|DESC|PRAGMA)\b/i';

    /** EXPLAIN ANALYZE executes the statement it explains (PostgreSQL, MariaDB ANALYZE): a write. */
    private const EXPLAIN_ANALYZE = '/^EXPLAIN\s*(?:ANALY[SZ]E\b|\([^)]*\bANALY[SZ]E\b)/i';

    /** Leading block and line comments, skipped before matching the first keyword. */
    private const LEADING_COMMENTS = '/^(?:\s|\(|\/\*.*?\*\/|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$))*/s';

    /** MySQL / MariaDB statements that commit implicitly, even inside a transaction. */
    private const IMPLICIT_COMMIT = '/^(?:CREATE|ALTER|DROP|TRUNCATE|RENAME|LOCK|UNLOCK|GRANT|REVOKE|BEGIN|START\s+TRANSACTION|ANALYZE|OPTIMIZE|REPAIR|INSTALL|UNINSTALL|FLUSH|LOAD\s+INDEX|CACHE\s+INDEX|SET\s+(?:PASSWORD|(?:SESSION\s+|@@(?:SESSION\.)?)?AUTOCOMMIT))\b/i';

    private bool $inTransaction = false;

    /** Whether the server is MySQL / MariaDB (read from the native connection the first time it matters). */
    private ?bool $mysql = null;

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
        $result = parent::query($sql);
        $this->executed($sql);

        return $result;
    }

    public function exec(string $sql): int
    {
        $affected = (int) parent::exec($sql);
        $this->executed($sql);

        return $affected;
    }

    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->inTransaction = true; // @igor-ignore — state of this driver connection, not of a request
    }

    public function commit(): void
    {
        // Audit TA2-1: a COMMIT the server refuses (a deferred constraint, PostgreSQL 40001 at commit) still ends the
        // transaction; a flag left true would stop counting the autocommit writes that follow.
        try {
            parent::commit();
        } finally {
            $this->inTransaction = false; // @igor-ignore — state of this driver connection, not of a request
        }

        $this->ledger->record();
    }

    public function rollBack(): void
    {
        $this->inTransaction = false; // @igor-ignore — state of this driver connection, not of a request
        parent::rollBack();
    }

    /** A statement of this connection ran successfully. */
    public function executed(string $sql): void
    {
        $statement = (string) preg_replace(self::LEADING_COMMENTS, '', $sql, 1);

        if ($this->isMultiStatement($sql)
            || (!$this->inTransaction && (preg_match(self::READ_ONLY, $statement) !== 1 || preg_match(self::EXPLAIN_ANALYZE, $statement) === 1))
            || ($this->inTransaction && preg_match(self::IMPLICIT_COMMIT, $statement) === 1 && $this->isMySql())) {
            $this->ledger->record();
        }
    }

    /** Several statements in one string (a `;` before the trailing whitespace): counted, whatever they are. */
    private function isMultiStatement(string $sql): bool
    {
        return str_contains(rtrim($sql, " \t\n\r;"), ';');
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
