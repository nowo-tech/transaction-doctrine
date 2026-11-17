<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;

use function ltrim;
use function preg_match;

/**
 * One driver connection: knows whether a real (driver-level) transaction is open, and records a successful COMMIT,
 * or a successful write statement outside a transaction, in the ledger. Savepoints are plain SQL inside the open
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

    private bool $inTransaction = false;

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
        parent::commit();
        $this->inTransaction = false; // @igor-ignore — state of this driver connection, not of a request
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
        if (!$this->inTransaction && preg_match(self::READ_ONLY, ltrim($sql, " \t\n\r(")) !== 1) {
            $this->ledger->record();
        }
    }
}
