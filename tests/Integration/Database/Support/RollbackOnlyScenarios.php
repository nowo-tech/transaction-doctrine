<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Nowo\TransactionDoctrine\ConnectionRepairer;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use ReflectionMethod;

use function version_compare;

/**
 * 1.7.2: the rollback-only flag survives Connection::close() on DBAL 3 and DBAL 4. After the repairer closes a
 * connection whose nested rollback failed (the server dropped the savepoints), the next transaction must still commit.
 * Shared by the real-database suite (MySQL, MariaDB, PostgreSQL) and the unit suite (SQLite file).
 *
 * @phpstan-require-extends DatabaseTestCase
 */
trait RollbackOnlyScenarios
{
    /**
     * A nested unit marked the transaction rollback-only, then the server dropped its savepoints (a MySQL / MariaDB
     * deadlock does; here the savepoint is released by hand): the nested rollback fails, the repairer closes.
     */
    private function scenarioTheFlagDoesNotOutliveAClosedConnection(bool $betweenRequests): void
    {
        $connection = $this->connection;
        $this->useSavepoints($connection);
        $connection->beginTransaction();
        $connection->beginTransaction();
        if ($betweenRequests) {
            $connection->beginTransaction();
        }

        $connection->setRollbackOnly();
        $connection->executeStatement('RELEASE SAVEPOINT ' . $this->savepoint($connection));

        $repairer = new ConnectionRepairer();
        $action   = $betweenRequests ? $repairer->betweenRequests($connection) : $repairer->afterFailure($connection, 0);

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, $action);
        self::assertSame(0, $connection->getTransactionNestingLevel());

        // A plain transaction on the reopened connection: before 1.7.2, CommitFailedRollbackOnly.
        $connection->beginTransaction();
        $connection->insert('ddrb_counter', ['id' => 6, 'counter_value' => 6]);
        $connection->commit();
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 6'));
    }

    /**
     * Audit VQ-6: auto-commit disabled. The repairer closes a nested leftover above the baseline (level 1, DBAL's own
     * transaction); on the reconnect DBAL opens level 1 itself, so the flag is cleared only when both levels are rolled
     * back. The connection is back at its baseline and the next commit succeeds.
     */
    private function scenarioTheFlagIsClearedWithAutoCommitOff(): void
    {
        $connection = $this->connection;
        $connection->close();
        $connection->setAutoCommit(false);
        $this->useSavepoints($connection);
        $connection->beginTransaction(); // connects: DBAL opens level 1, this BEGIN level 2
        $connection->beginTransaction();
        self::assertSame(3, $connection->getTransactionNestingLevel());
        $connection->setRollbackOnly();
        $connection->executeStatement('RELEASE SAVEPOINT ' . $this->savepoint($connection));

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->betweenRequests($connection, 1));
        self::assertSame(1, $connection->getTransactionNestingLevel());

        $connection->insert('ddrb_counter', ['id' => 8, 'counter_value' => 8]);
        $connection->commit();
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 8'));
        $connection->rollBack(); // the level DBAL opened after the commit
        $connection->setAutoCommit(true);
    }

    /** A flag left by a connection closed without the repairer: the runner clears it after its own BEGIN. */
    private function scenarioTheRunnerClearsAStaleFlag(): void
    {
        $this->connection->beginTransaction();
        $this->connection->setRollbackOnly();
        $this->connection->close();

        DoctrineTransactionRunner::forConnection($this->connection)->run(function (): void {
            $this->connection->insert('ddrb_counter', ['id' => 7, 'counter_value' => 7]);
        });

        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 7'));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    private function useSavepoints(Connection $connection): void
    {
        // DBAL 3 nests without savepoints unless told otherwise; DBAL 4 always uses them.
        if (version_compare((string) InstalledVersions::getVersion('doctrine/dbal'), '4.0.0', '<')) {
            $connection->setNestTransactionsWithSavepoints(true);
        }
    }

    /** The savepoint DBAL created for the current level (its name differs between DBAL versions). */
    private function savepoint(Connection $connection): string
    {
        return (string) (new ReflectionMethod(Connection::class, '_getNestedTransactionSavePointName'))->invoke($connection);
    }
}
