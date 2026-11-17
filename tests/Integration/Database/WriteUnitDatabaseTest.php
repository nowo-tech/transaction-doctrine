<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Counter;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\TransientFailureKind;
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
        $this->boot($server);
        $unit     = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0));
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

    private function shortLockWait(): void
    {
        $this->connection->executeStatement($this->isPostgreSql() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
    }
}
