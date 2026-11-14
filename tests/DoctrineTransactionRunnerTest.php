<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Exception;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DoctrineTransactionRunnerTest extends TestCase
{
    public function testConnectionRunnerCommitsAndReturnsTheResult(): void
    {
        $connection = $this->connection([0]);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('commit');

        self::assertSame('ok', DoctrineTransactionRunner::forConnection($connection)->run(static fn (): string => 'ok'));
    }

    public function testEntityManagerRunnerFlushesBeforeCommit(): void
    {
        $connection = $this->connection([0]);
        $manager    = $this->manager($connection, true);
        $order      = [];
        $manager->expects($this->once())->method('flush')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'flush';
        });
        $connection->method('commit')->willReturnCallback(static function () use (&$order): void {
            $order[] = 'commit';
        });

        self::assertSame(5, DoctrineTransactionRunner::forEntityManager($manager)->run(static fn (): int => 5));
        self::assertSame(['flush', 'commit'], $order);
    }

    public function testTransientFailureClosesTheConnectionResetsTheManagerAndBecomesTransientStorageFailure(): void
    {
        $connection = $this->connection([0, 2]);
        $connection->expects($this->once())->method('close');
        $connection->expects($this->never())->method('rollBack');
        $manager  = $this->manager($connection, false);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('orders')->willReturn($manager);
        $registry->expects($this->once())->method('resetManager')->with('orders');
        $deadlock = $this->deadlock();
        $caught   = null;

        try {
            DoctrineTransactionRunner::forEntityManager($manager, $registry, 'orders', 3)->run(static function () use ($deadlock): never {
                throw $deadlock;
            });
        } catch (TransientStorageFailure $failure) {
            $caught = $failure;
        }

        self::assertSame(3, $caught->retryAfterSeconds());
        self::assertSame($deadlock, $caught->getPrevious());
        self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $caught->getMessage());
    }

    public function testInsideACallersTransactionOnlyItsOwnLevelIsRolledBack(): void
    {
        $connection = $this->connection([1, 2, 2]);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('close');
        $bug = new LogicException('bug');

        $this->expectExceptionObject($bug);

        DoctrineTransactionRunner::forConnection($connection)->run(static function () use ($bug): never {
            throw $bug;
        });
    }

    public function testATransientStorageFailureFromANestedRunnerIsRethrownAsIs(): void
    {
        $inner = new TransientStorageFailure(9);

        $this->expectExceptionObject($inner);

        DoctrineTransactionRunner::forConnection($this->connection([0, 0]))->run(static function () use ($inner): never {
            throw $inner;
        });
    }

    public function testRegistryThatHoldsNoEntityManagerFallsBackToTheGivenOne(): void
    {
        $connection = $this->connection([0]);
        $manager    = $this->manager($connection, true);
        $registry   = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        $manager->expects($this->once())->method('flush');

        self::assertSame('x', DoctrineTransactionRunner::forEntityManager($manager, $registry)->run(static fn (): string => 'x'));
    }

    /**
     * @param list<int> $levels Nesting levels returned on consecutive calls
     */
    private function connection(array $levels): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(...$levels);

        return $connection;
    }

    private function manager(Connection $connection, bool $open): EntityManagerInterface&MockObject
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn($open);

        return $manager;
    }

    private function deadlock(): DeadlockException
    {
        $driver = new class('Deadlock found', 1213) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return '40001';
            }
        };

        return new DeadlockException($driver, null);
    }
}
