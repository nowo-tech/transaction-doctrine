<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use LogicException;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingDriver;
use Throwable;

use function usleep;

/**
 * 1.9: the per-connection outermost-commit epoch (CommitLedger::mark() / outcome()). Shared by the real-database suite
 * and the unit suite (SQLite file). The test sets $this->ledger before booting, so the connection is tracked.
 *
 * @phpstan-require-extends DatabaseTestCase
 */
trait EpochScenarios
{
    private function trackedLedger(): CommitLedger
    {
        self::assertInstanceOf(CommitLedger::class, $this->ledger);

        return $this->ledger;
    }

    /** A mark follows the transaction it was taken in: open, then committed; another one rolled back. */
    private function scenarioAMarkFollowsItsOwnTransaction(): void
    {
        $ledger = $this->trackedLedger();
        $before = $ledger->mark($this->connection);
        self::assertTrue($before->isAutoCommit());
        self::assertTrue($ledger->outcome($before));

        $this->connection->beginTransaction();
        $this->connection->insert('ddrb_counter', ['id' => 10, 'counter_value' => 1]);
        $committed = $ledger->mark($this->connection);
        self::assertFalse($committed->isAutoCommit());
        self::assertNull($ledger->outcome($committed), 'still open');
        $this->connection->commit();
        self::assertTrue($ledger->outcome($committed));

        $this->connection->beginTransaction();
        $this->connection->insert('ddrb_counter', ['id' => 11, 'counter_value' => 1]);
        $rolledBack = $ledger->mark($this->connection);
        $this->connection->rollBack();
        self::assertFalse($ledger->outcome($rolledBack));
        self::assertTrue($ledger->outcome($committed), 'a later transaction never changes an earlier outcome');
    }

    /**
     * The phantom case (Uptime's audit-trail outbox, UserKit's savepoint case): a bundle releases its savepoint inside a
     * host transaction and marks it; the host rolls back; a later, unrelated write commits. The ledger count moved (a
     * CommitMark says "committed"), but the marked transaction did not commit.
     */
    private function scenarioARolledBackHostTransactionIsNotAPhantom(): void
    {
        $ledger = $this->trackedLedger();
        $this->connection->beginTransaction(); // the host's
        $watermark = new CommitMark($ledger);
        $this->connection->beginTransaction(); // the bundle's (a savepoint)
        $this->connection->insert('ddrb_counter', ['id' => 12, 'counter_value' => 1]);
        $this->connection->commit(); // savepoint released
        $mark = $ledger->mark($this->connection);
        $this->connection->rollBack(); // the host gives up

        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1'); // auto-committed
        self::assertTrue($watermark->committedSince(), 'the process-wide count moved');
        self::assertFalse($ledger->outcome($mark), 'but the marked transaction rolled back');
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 12'));
    }

    /** A connection closed with its transaction open rolls it back (the server does), and a reconnect starts afresh. */
    private function scenarioAClosedConnectionRollsItsTransactionBack(): void
    {
        $ledger = $this->trackedLedger();
        $this->connection->beginTransaction();
        $mark = $ledger->mark($this->connection);
        $this->connection->close();
        self::assertFalse($ledger->outcome($mark));

        $this->connection->beginTransaction();
        $again = $ledger->mark($this->connection);
        $this->connection->commit();
        self::assertTrue($ledger->outcome($again));
        self::assertFalse($ledger->outcome($mark));
    }

    /** A ledger that does not track the connection refuses to mark its transaction. */
    private function scenarioAnUntrackedTransactionCannotBeMarked(): void
    {
        $this->connection->beginTransaction();

        try {
            (new CommitLedger())->mark($this->connection);
            self::fail('No LogicException.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('CommitTrackingMiddleware', $exception->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * Audit WD-1: a statement that failed inside the transaction (a caught unique violation), then COMMIT. PostgreSQL
     * answers that COMMIT with a ROLLBACK which PDO reports as a success: the mark reads false and nothing committed.
     * Other servers keep the transaction: true and the row is there. A rollback to a savepoint taken before the failure
     * (DBAL's nested rollBack()) ends the failure everywhere: true.
     */
    private function scenarioAFailedStatementBeforeCommit(): void
    {
        $ledger = $this->trackedLedger();
        $this->connection->beginTransaction();
        $this->connection->insert('ddrb_counter', ['id' => 20, 'counter_value' => 1]);
        $mark = $ledger->mark($this->connection);
        $this->failsWith(fn (): mixed => $this->connection->insert('ddrb_counter', ['id' => 20, 'counter_value' => 2]));
        $this->connection->commit();

        $present = (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 20');
        self::assertSame($this->isPostgreSql() ? 0 : 1, $present);
        self::assertSame($present === 1, $ledger->outcome($mark), 'the outcome matches what the server kept');

        if (CommitTrackingDriver::driverCommitReturnsResult()) {
            $this->connection->setNestTransactionsWithSavepoints(true); // DBAL 3 (always on in DBAL 4)
        }

        $this->connection->beginTransaction();
        $this->connection->insert('ddrb_counter', ['id' => 21, 'counter_value' => 1]);
        $this->connection->beginTransaction(); // a savepoint
        $this->failsWith(fn (): mixed => $this->connection->insert('ddrb_counter', ['id' => 21, 'counter_value' => 2]));
        $this->connection->rollBack(); // to the savepoint: the failure is undone
        $recovered = $ledger->mark($this->connection);
        $this->connection->commit();
        self::assertTrue($ledger->outcome($recovered));
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 21'));
    }

    /**
     * Audit WD-7, MySQL / MariaDB: a statement that commits implicitly (DDL) inside the transaction commits it: the
     * mark reads true at once, and the row is durable whatever follows. A deadlock rolls the whole transaction back: the
     * mark reads false at once.
     */
    private function scenarioMySqlEndsTheTransactionItself(): void
    {
        $ledger = $this->trackedLedger();
        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_ddl');
        $this->connection->beginTransaction();
        $this->connection->insert('ddrb_counter', ['id' => 22, 'counter_value' => 1]);
        $mark = $ledger->mark($this->connection);
        $this->connection->executeStatement('CREATE TABLE ddrb_ddl (id INT)');
        self::assertTrue($ledger->outcome($mark), 'the DDL committed the transaction');
        self::assertTrue($ledger->ended($mark));
        self::assertTrue($this->connection->isTransactionActive(), 'DBAL still counts it open');
        self::assertTrue($ledger->mark($this->connection)->isAutoCommit(), 'audit WD-R2: what follows auto-commits');
        $this->failsWith(function (): void {
            $this->connection->rollBack();
        }); // the server has no transaction left
        self::assertTrue($ledger->outcome($mark));
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 22'));
        $this->connection->executeStatement('DROP TABLE ddrb_ddl');

        $this->connection->beginTransaction();
        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
        $deadlocked = $ledger->mark($this->connection);
        $this->startDeadlockingPeer();
        $this->failsWith(fn (): mixed => $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 2'));
        self::assertFalse($ledger->outcome($deadlocked), 'the deadlock rolled the transaction back');
        self::assertTrue($ledger->mark($this->connection)->isAutoCommit(), 'audit WD-R2: what follows auto-commits');
        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));

        try {
            $this->connection->rollBack();
        } catch (Throwable) {
            // the server has no transaction left
        }

        self::assertFalse($ledger->outcome($deadlocked));
    }

    /**
     * Audit WD-7: the connection lost during COMMIT (killed by another session): whether the server committed is
     * unknown, so the mark reads null, but it has ended (unlike an open transaction). A reconnect starts afresh.
     */
    private function scenarioALostCommitIsUnknown(): void
    {
        $ledger = $this->trackedLedger();
        $this->connection->beginTransaction();
        $this->connection->insert('ddrb_counter', ['id' => 23, 'counter_value' => 1]);
        $mark = $ledger->mark($this->connection);
        self::assertFalse($ledger->ended($mark));
        $session = (string) $this->connection->fetchOne($this->isPostgreSql() ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()');
        self::assertSame('OK', $this->otherSession()->execute($this->isPostgreSql() ? 'SELECT pg_terminate_backend(' . $session . ')' : 'KILL ' . $session));
        usleep(200_000);

        $this->failsWith(function (): void {
            $this->connection->commit();
        });
        self::assertNull($ledger->outcome($mark), 'unknown');
        self::assertTrue($ledger->ended($mark), 'but over');

        $this->connection->close();
        $this->connection->beginTransaction();
        $again = $ledger->mark($this->connection);
        $this->connection->commit();
        self::assertTrue($ledger->outcome($again));
        self::assertNull($ledger->outcome($mark));
    }

    /** @param callable(): mixed $call */
    private function failsWith(callable $call): void
    {
        try {
            $call();
        } catch (Throwable) {
            return;
        }

        self::fail('No failure.');
    }
}
