<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Counter;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\DoctrineStateRepairer;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Write units against real MySQL 8.4, MariaDB 11.4 and PostgreSQL 16: a real deadlock and real lock wait timeouts are
 * replayed (the bundle runner's TransientStorageFailure included), the last one becomes ConcurrencyConflict, and the
 * connection and entity manager are usable afterwards.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WriteUnitDatabaseTest extends DatabaseTestCase
{
    /**
     * Attempt 1 is the deadlock victim inside a bundle runner (TransientStorageFailure); before attempt 2 the peer
     * commits, and the replay commits on a reset manager.
     */
    #[DataProvider('mysqlFamily')]
    public function testARealDeadlockIsReplayedAndTheReplayCommits(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $unit     = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0), null, null, null, null, $this->ledger);
        $attempts = [];

        $unit->run(function (int $attempt, ?Throwable $previous) use (&$attempts): void {
            $attempts[] = [$attempt, $previous instanceof Throwable ? $previous::class : null];
            if ($attempt === 2) {
                self::assertSame('OK', $this->otherSession()->await());
                self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
            }

            DoctrineTransactionRunner::forEntityManager($this->manager(), $this->registry)->run(function () use ($attempt): void {
                $manager = $this->manager();
                $manager->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
                if ($attempt === 1) {
                    $this->startDeadlockingPeer();
                }

                $counter = $manager->find(Counter::class, 2);
                self::assertInstanceOf(Counter::class, $counter);
                ++$counter->value;
            });
        });

        self::assertCount(2, $attempts);
        self::assertNull($attempts[0][1]);
        self::assertNotNull($attempts[1][1]);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(101, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
        self::assertSame(101, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
    }

    /**
     * A row another session holds: attempt 1 waits out the lock wait timeout, the peer commits, attempt 2 writes.
     */
    #[DataProvider('allServers')]
    public function testALockWaitTimeoutIsReplayedOnEveryServer(string $server): void
    {
        $this->boot($server);
        $this->shortLockWait();
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 7 WHERE id = 1'));
        $unit = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0));

        $result = $unit->run(function (int $attempt): int {
            if ($attempt === 2) {
                self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
            }

            $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame(8, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
    }

    #[DataProvider('allServers')]
    public function testTheLastLockWaitTimeoutBecomesConcurrencyConflictAndTheConnectionStaysUsable(string $server): void
    {
        $this->boot($server);
        $this->shortLockWait();
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 7 WHERE id = 1'));
        $unit  = new WriteUnit($this->registry, new WriteUnitPolicy(2, 0, 0, 0));
        $calls = 0;

        try {
            $unit->run(function () use (&$calls): void {
                ++$calls;
                $this->connection->transactional(function (): void {
                    $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 1 WHERE id = 2');
                    $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 1 WHERE id = 1');
                });
            });
            self::fail('No conflict.');
        } catch (ConcurrencyConflict $conflict) {
            self::assertSame(2, $calls);
            self::assertSame(2, $conflict->attempts);
            self::assertSame(TransientFailureKind::LockWaitTimeout, $conflict->kind);
        }

        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
        self::assertTrue($this->manager()->isOpen());
    }

    /**
     * Inside a transaction the caller opened, the unit runs once: the database may have rolled the caller's whole
     * transaction back.
     */
    #[DataProvider('allServers')]
    public function testInsideACallersTransactionTheUnitRunsOnce(string $server): void
    {
        $this->boot($server);
        $this->shortLockWait();
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 7 WHERE id = 1'));
        $unit  = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0));
        $calls = 0;

        $this->connection->beginTransaction();
        $caught = null;

        try {
            $unit->run(function () use (&$calls): void {
                ++$calls;
                $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 1 WHERE id = 1');
            });
        } catch (Throwable $failure) {
            $caught = $failure;
        } finally {
            $this->connection->rollBack();
            self::assertSame('OK', $this->otherSession()->execute('ROLLBACK'));
        }

        self::assertNotNull($caught);
        self::assertNotInstanceOf(ConcurrencyConflict::class, $caught);
        self::assertSame(1, $calls);
    }

    /**
     * After a database failure the repairer rolls back a leftover transaction, resets a closed manager and pings the
     * connection (it stays open: it answers).
     */
    #[DataProvider('allServers')]
    public function testTheRepairerLeavesAWorkingConnectionAndManager(string $server): void
    {
        $this->boot($server);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 99 WHERE id = 1');
        $this->manager()->close();

        (new DoctrineStateRepairer($this->registry))->afterDatabaseFailure();

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertTrue($this->connection->isConnected());
        self::assertSame(1, $this->registry->resets);
        self::assertTrue($this->manager()->isOpen());
        self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
    }

    /**
     * Audit SL-M1 (probe P1): a unit whose first commit succeeded and whose second deadlocks is not replayed, so the
     * first write and its side effect happen once; the conflict says it had committed.
     */
    #[DataProvider('mysqlFamily')]
    public function testAUnitThatAlreadyCommittedIsNotReplayed(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $unit   = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0), null, null, null, null, $this->ledger);
        $mails  = 0;
        $caught = null;

        try {
            $unit->run(function (int $attempt) use (&$mails): void {
                DoctrineTransactionRunner::forEntityManager($this->manager(), $this->registry)->run(function (): void {
                    $this->manager()->getConnection()->executeStatement('INSERT INTO ddrb_ballast (id) VALUES (1001)');
                });
                ++$mails;
                DoctrineTransactionRunner::forEntityManager($this->manager(), $this->registry)->run(function () use ($attempt): void {
                    $connection = $this->manager()->getConnection();
                    $connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
                    if ($attempt === 1) {
                        $this->startDeadlockingPeer();
                    }
                    $connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 2');
                });
            });
        } catch (ConcurrencyConflict $conflict) {
            $caught = $conflict;
        }

        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        self::assertNotNull($caught);
        self::assertTrue($caught->committed);
        self::assertNull($caught->retryAfterSeconds());
        self::assertSame(1, $caught->attempts);
        self::assertSame(1, $mails);
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_ballast WHERE id > 1000'));
    }

    /**
     * Audit SL-M2 (probe P2): a caller's unflushed changes in the shared manager are never cleared for a replay: the
     * unit runs once, and the caller's flush afterwards writes its changes.
     */
    #[DataProvider('mysqlFamily')]
    public function testACallersPendingChangesSurviveAConflict(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $mine    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $mine);
        $mine->value = 555;
        $manager->persist(new Counter(3, 33));
        $unit   = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0));
        $calls  = 0;
        $caught = null;

        try {
            $unit->run(function () use (&$calls): void {
                ++$calls;
                $this->connection->transactional(function (): void {
                    $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
                    $this->startDeadlockingPeer();
                    $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 2');
                });
            });
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertNotNull($caught);
        self::assertNotInstanceOf(ConcurrencyConflict::class, $caught);
        self::assertSame(1, $calls);
        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        self::assertTrue($this->manager()->contains($mine));

        $this->manager()->flush();

        self::assertSame(555, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 3'));
    }

    /**
     * Audit SL-D-L1: a MySQL / MariaDB implicit commit inside a transaction, and PostgreSQL EXPLAIN ANALYZE of a write,
     * are counted as durable writes; DDL inside a PostgreSQL transaction is not (it is transactional).
     */
    #[DataProvider('allServers')]
    public function testCommitDetectionSeesImplicitCommitsAndExplainAnalyze(string $server): void
    {
        $ledger       = new CommitLedger();
        $this->ledger = $ledger;
        $this->boot($server);
        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_implicit');
        $before = $ledger->count();

        $this->connection->beginTransaction();
        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 4242 WHERE id = 1');
        $this->connection->executeStatement('CREATE TABLE ddrb_implicit (id INT)');

        try {
            $this->connection->rollBack();
        } catch (Throwable) {
            // MySQL / MariaDB: the CREATE TABLE already committed, so there is no transaction left to roll back.
            $this->connection->close();
        }

        if ($this->isPostgreSql()) {
            self::assertSame($before, $ledger->count());
            $this->connection->executeStatement('EXPLAIN ANALYZE UPDATE ddrb_counter SET counter_value = counter_value + 5 WHERE id = 2');
            self::assertSame($before + 1, $ledger->count());
        } else {
            self::assertSame($before + 1, $ledger->count());
            self::assertSame(4242, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
        }

        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_implicit');
    }

    private function shortLockWait(): void
    {
        $this->connection->executeStatement($this->isPostgreSql() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
    }
}
