<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use DomainException;
use Exception;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\FlushObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;

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

    public function testTransientFailureRepairsTheConnectionResetsTheManagerAndBecomesTransientStorageFailure(): void
    {
        $connection = $this->connection([0, 2, 2]);
        $connection->expects($this->once())->method('rollBack')->willThrowException(new LogicException('1305 SAVEPOINT DOCTRINE_2 does not exist'));
        $connection->expects($this->once())->method('close');
        $manager = $this->manager($connection, false);
        $manager->expects($this->once())->method('close');
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

    public function testADomainFailureThatLeftChangesPendingClearsTheManagerWithoutResettingIt(): void
    {
        $connection = $this->connection([0, 1, 1]);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('close');
        $manager = $this->manager($connection, true, pending: true);
        $manager->expects($this->once())->method('clear');
        $manager->expects($this->never())->method('close');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects($this->never())->method('resetManager');
        $manager->expects($this->never())->method('flush');
        $bug = new LogicException('validation failed');

        $this->expectExceptionObject($bug);

        DoctrineTransactionRunner::forEntityManager($manager, $registry)->run(static function () use ($bug): never {
            throw $bug;
        });
    }

    public function testADomainRefusalWithNothingPendingLeavesTheHostManagerAlone(): void
    {
        $connection = $this->connection([0, 1, 1]);
        $manager    = $this->manager($connection, true);
        $manager->expects($this->never())->method('clear');
        $manager->expects($this->never())->method('close');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects($this->never())->method('resetManager');
        $refusal = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forEntityManager($manager, $registry)->run(static function () use ($refusal): never {
            throw $refusal;
        });
    }

    public function testADomainFailureAfterAFlushInsideTheOperationClears(): void
    {
        $connection = $this->connection([0, 1, 1]);
        /** @var list<FlushObserver> $listeners */
        $listeners = [];
        $events    = $this->createMock(EventManager::class);
        $events->method('addEventListener')->willReturnCallback(static function (array|string $names, object $listener) use (&$listeners): void {
            if ($listener instanceof FlushObserver) {
                $listeners[] = $listener;
            }
        });
        $events->expects($this->once())->method('removeEventListener');
        $manager = $this->manager($connection, true, events: $events);
        $manager->expects($this->once())->method('clear');
        $refusal = new DomainException('refused after flushing');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forEntityManager($manager)->run(static function () use (&$listeners, $refusal): never {
            foreach ($listeners as $listener) {
                $listener->postFlush();   // the operation flushed: its writes are rolled back, the identity map is stale
            }

            throw $refusal;
        });
    }

    public function testAnUnreadableUnitOfWorkCountsAsPending(): void
    {
        $connection = $this->connection([0, 1, 1]);
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('computeChangeSets')->willThrowException(new LogicException('new entity found through a relationship'));
        $manager = $this->manager($connection, true, unitOfWork: $unitOfWork);
        $manager->expects($this->once())->method('clear');
        $refusal = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forEntityManager($manager)->run(static function () use ($refusal): never {
            throw $refusal;
        });
    }

    public function testAStorageFailureResetsAnOpenManagerThroughTheRegistry(): void
    {
        $connection = $this->connection([0, 1, 1]);
        $manager    = $this->manager($connection, true);
        $manager->expects($this->once())->method('close');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects($this->once())->method('resetManager');
        $duplicate = new UniqueConstraintViolationException($this->driverError(1062, '23000'), null);

        $this->expectExceptionObject($duplicate);

        DoctrineTransactionRunner::forEntityManager($manager, $registry)->run(static function () use ($duplicate): never {
            throw $duplicate;
        });
    }

    public function testAStorageFailureWithoutARegistryClearsAnOpenManager(): void
    {
        $connection = $this->connection([0, 1, 1]);
        $manager    = $this->manager($connection, true);
        $manager->expects($this->once())->method('clear');
        $duplicate = new LogicException('wrapper', 0, new UniqueConstraintViolationException($this->driverError(1062, '23000'), null));

        $this->expectExceptionObject($duplicate);

        DoctrineTransactionRunner::forEntityManager($manager)->run(static function () use ($duplicate): never {
            throw $duplicate;
        });
    }

    public function testAClosedManagerWithoutARegistryIsLeftAsItIs(): void
    {
        $manager = $this->manager($this->connection([0, 0]), false);
        $manager->expects($this->never())->method('clear');
        $bug = new LogicException('bug');

        $this->expectExceptionObject($bug);

        DoctrineTransactionRunner::forEntityManager($manager)->run(static function () use ($bug): never {
            throw $bug;
        });
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

    private function manager(Connection $connection, bool $open, bool $pending = false, ?UnitOfWork $unitOfWork = null, ?EventManager $events = null): EntityManagerInterface&MockObject
    {
        if (!$unitOfWork instanceof UnitOfWork) {
            $unitOfWork = $this->createMock(UnitOfWork::class);
            $unitOfWork->method('getScheduledEntityInsertions')->willReturn($pending ? [new stdClass()] : []);
            $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
            $unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
            $unitOfWork->method('getScheduledCollectionDeletions')->willReturn([]);
            $unitOfWork->method('getScheduledCollectionUpdates')->willReturn([]);
        }

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn($open);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        // ORM 2's interface has no native return type here, so the mock would return null.
        $manager->method('getEventManager')->willReturn($events ?? $this->createMock(EventManager::class));

        return $manager;
    }

    private function driverError(int $code, string $sqlState): DriverExceptionInterface
    {
        return new class('driver error', $code, $sqlState) extends Exception implements DriverExceptionInterface {
            public function __construct(string $message, int $code, private readonly string $sqlState)
            {
                parent::__construct($message, $code);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
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
