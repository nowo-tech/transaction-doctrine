<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Counter;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The default runner against real servers, including the QA audit regressions (APPENDIX-QA §3, TD-H1 / TD-M1),
 * ported from its proofs of concept with the assertions inverted.
 */
final class DoctrineTransactionRunnerDatabaseTest extends DatabaseTestCase
{
    /**
     * QA-TD-H1: a failed run leaves nothing pending, with or without a registry; the next run commits only its own work.
     */
    #[DataProvider('allServers')]
    public function testAFailedRunLeavesNoPendingChanges(string $server): void
    {
        $this->boot($server);

        foreach ([true, false] as $withRegistry) {
            $manager = $withRegistry ? $this->manager() : self::entityManager($this->connection);
            $runner  = $withRegistry
                ? DoctrineTransactionRunner::forEntityManager($manager, $this->registry)
                : DoctrineTransactionRunner::forEntityManager($manager);
            $failed = $withRegistry ? 10 : 20;

            $failure = $this->failure($runner, static function () use ($manager, $failed): never {
                $manager->persist(new Counter($failed));

                throw new DomainException('validation failed');
            });
            self::assertInstanceOf(DomainException::class, $failure);

            $current = $withRegistry ? $this->manager() : $manager;
            self::assertTrue($current->isOpen());
            self::assertSame([], $current->getUnitOfWork()->getScheduledEntityInsertions());

            $runner->run(static function () use ($current, $failed): void {
                $current->persist(new Counter($failed + 1));
            });
            $current->flush();   // a later flush in the same request

            self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = ' . $failed), 'The failed run committed nothing.');
            self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = ' . ($failed + 1)));
        }
    }

    /**
     * QA-TD-M1: a domain failure rolls back and keeps the connection, so session variables and session locks survive.
     */
    #[DataProvider('allServers')]
    public function testADomainFailureKeepsSessionStateAndLocks(string $server): void
    {
        $this->boot($server);

        if ($this->isPostgreSql()) {
            $this->connection->fetchOne('SELECT pg_advisory_lock(4242)');
        } else {
            $this->connection->executeStatement("SET @td_session = 'kept'");
            $this->connection->fetchOne("SELECT GET_LOCK('td_qa_lock', 0)");
        }

        $failure = $this->failure(DoctrineTransactionRunner::forConnection($this->connection), function (): never {
            $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 13 WHERE id = 1');

            throw new DomainException('x');
        });
        self::assertInstanceOf(DomainException::class, $failure);

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));

        if ($this->isPostgreSql()) {
            self::assertFalse((bool) $this->freshValue('SELECT pg_try_advisory_lock(4242)'), 'The advisory lock is still held.');
        } else {
            self::assertSame('kept', $this->connection->fetchOne('SELECT @td_session'));
            self::assertNotNull($this->freshValue("SELECT IS_USED_LOCK('td_qa_lock')"));
        }
    }

    /**
     * A real deadlock in a nested flush: TransientStorageFailure, the connection repaired (nesting level 0), nothing
     * pending, and the next run commits.
     */
    #[DataProvider('mysqlFamily')]
    public function testANestedDeadlockBecomesTransientStorageFailureAndLeavesAWorkingConnection(string $server): void
    {
        $this->boot($server);
        $runner = DoctrineTransactionRunner::forEntityManager($this->manager(), $this->registry, null, 2);
        $caught = null;

        try {
            $runner->run(function (): void {
                $manager = $this->manager();
                $manager->wrapInTransaction(function (EntityManagerInterface $manager): void {
                    $manager->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
                    $this->startDeadlockingPeer();
                    $counter = $manager->find(Counter::class, 2);
                    self::assertInstanceOf(Counter::class, $counter);
                    ++$counter->value;
                    $manager->flush();
                });
            });
        } catch (TransientStorageFailure $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(TransientStorageFailure::class, $caught);
        self::assertSame(2, $caught->retryAfterSeconds());
        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());

        $runner->run(function (): void {
            $counter = $this->manager()->find(Counter::class, 1);
            self::assertInstanceOf(Counter::class, $counter);
            $counter->value = 500;
        });
        self::assertSame(500, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
    }

    #[DataProvider('mysqlFamily')]
    public function testALockWaitTimeoutBecomesTransientStorageFailure(string $server): void
    {
        $this->boot($server);
        $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 99 WHERE id = 1'));

        $this->expectException(TransientStorageFailure::class);

        DoctrineTransactionRunner::forConnection($this->connection)->run(function (): void {
            $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 5 WHERE id = 1');
        });
    }

    public function testAPostgreSqlSerializationFailureBecomesTransientStorageFailure(): void
    {
        $this->boot('TD_POSTGRES_URL');
        $caught = null;

        try {
            DoctrineTransactionRunner::forConnection($this->connection)->run(function (): void {
                $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->connection->fetchOne('SELECT counter_value FROM ddrb_counter WHERE id = 1');
                self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = counter_value + 100 WHERE id = 1'));
                $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
            });
        } catch (TransientStorageFailure $failure) {
            $caught = $failure;
        }

        self::assertInstanceOf(TransientStorageFailure::class, $caught);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * An implicit commit drops the savepoints too; that 1305 is not transient: the unique violation is rethrown as is.
     */
    #[DataProvider('mysqlFamily')]
    public function testALostSavepointWithoutADeadlockIsNotTransient(string $server): void
    {
        $this->boot($server);
        $this->connection->executeStatement('DROP TABLE IF EXISTS td_lazy');
        $caught = null;

        try {
            DoctrineTransactionRunner::forConnection($this->connection)->run(function (): void {
                $this->connection->transactional(static function (Connection $connection): void {
                    $connection->executeStatement('CREATE TABLE IF NOT EXISTS td_lazy (id INT)');
                    $connection->insert('ddrb_counter', ['id' => 1, 'counter_value' => 0]);
                });
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        self::assertNotInstanceOf(TransientStorageFailure::class, $caught);
        $unique = false;

        for ($current = $caught; $current instanceof Throwable; $current = $current->getPrevious()) {
            $unique = $unique || $current instanceof UniqueConstraintViolationException;
        }

        self::assertTrue($unique);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * @param callable(): void $operation
     */
    private function failure(DoctrineTransactionRunner $runner, callable $operation): ?Throwable
    {
        try {
            $runner->run($operation);
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }
}
