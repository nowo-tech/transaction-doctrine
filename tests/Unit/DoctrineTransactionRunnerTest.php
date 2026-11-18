<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use DomainException;
use Exception;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\RunObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SplObjectStorage;
use stdClass;

use function array_column;

use const PHP_INT_MAX;

final class DoctrineTransactionRunnerTest extends TestCase
{
    public function testConnectionRunnerCommitsAndReturnsTheResult(): void
    {
        $connection = $this->connection([0]);
        $connection->expects($this->once())->method('beginTransaction');
        $connection->expects($this->once())->method('commit');

        $result = uniqid('result', true);

        self::assertSame($result, DoctrineTransactionRunner::forConnection($connection)->run(static fn (): string => $result));
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

        $result = random_int(1, PHP_INT_MAX);

        self::assertSame($result, DoctrineTransactionRunner::forEntityManager($manager)->run(static fn (): int => $result));
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
        /** @var list<RunObserver> $listeners */
        $listeners = [];
        $events    = $this->createMock(EventManager::class);
        $events->method('addEventListener')->willReturnCallback(static function (array|string $names, object $listener) use (&$listeners): void {
            if ($listener instanceof RunObserver) {
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
        $unitOfWork->method('getIdentityMap')->willReturn([]);   // ORM 2: no native return type, the mock would return null
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

        $result = uniqid('result', true);

        self::assertSame($result, DoctrineTransactionRunner::forEntityManager($manager, $registry)->run(static fn (): string => $result));
    }

    /**
     * @param list<int> $levels Nesting levels returned on consecutive calls
     */
    private function connection(array $levels): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(...$levels);

        // DBAL 3 legs: the mock would otherwise report emulated nesting.
        $connection->method('getNestTransactionsWithSavepoints')->willReturn(true);

        return $connection;
    }

    public function testARefusalDetachesTheEntitiesTheRunLoadedAndKeepsTheCallers(): void
    {
        $caller     = new stdClass();
        $loaded     = new stdClass();
        $observers  = [];
        $events     = $this->capturingEvents($observers);
        $unitOfWork = $this->unitOfWork(['App\\Thing' => [1 => $caller]], ['App\\Thing' => [1 => $caller, 2 => $loaded]]);
        $manager    = $this->manager($this->connection([0, 1, 1]), true, unitOfWork: $unitOfWork, events: $events);
        $detached   = new SplObjectStorage();
        $manager->method('contains')->willReturnCallback(static fn (object $entity): bool => !$detached->contains($entity));
        $manager->expects($this->once())->method('detach')->with($loaded)->willReturnCallback(static function (object $entity) use ($detached): void {
            $detached->attach($entity);
        });
        $manager->expects($this->never())->method('clear');
        $refusal = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forEntityManager($manager)->run(function () use (&$observers, $loaded, $refusal): never {
            $this->load($observers, $loaded);   // a row the run wrote through DBAL, then found

            throw $refusal;
        });
    }

    public function testAReferenceTheRunCreatedIsDetachedToo(): void
    {
        $proxy      = new stdClass();   // getReference(): no postLoad
        $unitOfWork = $this->unitOfWork([], ['App\\Thing' => [9 => $proxy]]);
        $manager    = $this->manager($this->connection([0, 1, 1]), true, unitOfWork: $unitOfWork);
        $manager->method('contains')->willReturn(true);
        $manager->expects($this->once())->method('detach')->with($proxy);
        $refusal = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forEntityManager($manager)->run(static function () use ($refusal): never {
            throw $refusal;
        });
    }

    public function testADetachThatCascadedIntoACallersEntityClears(): void
    {
        $caller     = new stdClass();
        $loaded     = new stdClass();
        $unitOfWork = $this->unitOfWork(['App\\Thing' => [1 => $caller]], ['App\\Thing' => [1 => $caller], 'App\\Note' => [1 => $loaded]]);
        $manager    = $this->manager($this->connection([0, 1, 1]), true, unitOfWork: $unitOfWork);
        $detached   = new SplObjectStorage();
        $manager->method('contains')->willReturnCallback(static fn (object $entity): bool => !$detached->contains($entity));
        $manager->method('detach')->willReturnCallback(static function () use ($detached, $caller): void {
            $detached->attach($caller);   // cascade: detach reached the caller's entity
        });
        $manager->expects($this->once())->method('clear');
        $refusal = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forEntityManager($manager)->run(static function () use ($refusal): never {
            throw $refusal;
        });
    }

    public function testARefusalAfterReHydratingACallersEntityClears(): void
    {
        $caller     = new stdClass();
        $observers  = [];
        $events     = $this->capturingEvents($observers);
        $unitOfWork = $this->unitOfWork(['App\\Thing' => [1 => $caller]]);
        $manager    = $this->manager($this->connection([0, 1, 1]), true, unitOfWork: $unitOfWork, events: $events);
        $manager->expects($this->once())->method('clear');
        $manager->expects($this->never())->method('detach');
        $refusal = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forEntityManager($manager)->run(function () use (&$observers, $caller, $refusal): never {
            $this->load($observers, $caller);   // refresh() or a proxy initialised: the caller's object holds rolled-back data

            throw $refusal;
        });
    }

    public function testScheduledWorkIsDetectedWithoutComputingChangeSets(): void
    {
        $unitOfWork = $this->unitOfWork(insertions: [new stdClass()]);
        $unitOfWork->expects($this->never())->method('computeChangeSets');
        $manager = $this->manager($this->connection([0, 1, 1]), true, unitOfWork: $unitOfWork);
        $manager->expects($this->once())->method('clear');
        $refusal = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forEntityManager($manager)->run(static function () use ($refusal): never {
            throw $refusal;
        });
    }

    public function testTheHostRunnerFlushesBeforeCommitLikeTheOwnedOne(): void
    {
        $connection = $this->connection([0]);
        $manager    = $this->manager($connection, true);
        $manager->expects($this->once())->method('flush');
        $connection->expects($this->once())->method('commit');

        $result = random_int(1, PHP_INT_MAX);

        self::assertSame($result, DoctrineTransactionRunner::forHostEntityManager($manager)->run(static fn (): int => $result));
    }

    /** A domain failure after a flush inside the run: the owned runner clears, the host runner does not. */
    public function testTheHostRunnerDoesNotClearAfterAFlushedRefusal(): void
    {
        $observers = [];
        $events    = $this->capturingEvents($observers);
        $manager   = $this->manager($this->connection([0, 1, 1]), true, true, events: $events);
        $manager->method('contains')->willReturn(false);
        $manager->expects($this->never())->method('clear');
        $manager->expects($this->never())->method('detach');
        $refusal = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forHostEntityManager($manager)->run(static function () use (&$observers, $refusal): never {
            foreach ($observers as $observer) {
                $observer->postFlush();
            }

            throw $refusal;
        });
    }

    public function testTheHostRunnerReplacesOnlyAClosedManager(): void
    {
        $manager = $this->manager($this->connection([0, 1, 1]), false);
        $manager->expects($this->never())->method('close');
        $manager->expects($this->never())->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('orders')->willReturn($manager);
        $registry->expects($this->once())->method('resetManager')->with('orders');
        $logger = new RecordingLogger();
        $bug    = new LogicException('bug');

        $this->expectExceptionObject($bug);

        try {
            DoctrineTransactionRunner::forHostEntityManager($manager, $registry, 'orders', 1, $logger)->run(static function () use ($bug): never {
                throw $bug;
            });
        } finally {
            self::assertContains('Replacing the closed host entity manager.', array_column($logger->records, 'message'));
        }
    }

    public function testTheHostRunnerLeavesAClosedManagerWithoutARegistry(): void
    {
        $manager = $this->manager($this->connection([0, 1, 1]), false);
        $manager->expects($this->never())->method('close');
        $manager->expects($this->never())->method('clear');
        $manager->expects($this->never())->method('detach');
        $bug = new LogicException('bug');

        $this->expectExceptionObject($bug);

        DoctrineTransactionRunner::forHostEntityManager($manager)->run(static function () use ($bug): never {
            throw $bug;
        });
    }

    /**
     * @param list<RunObserver> $observers
     */
    private function capturingEvents(array &$observers): EventManager&MockObject
    {
        $events = $this->createMock(EventManager::class);
        $events->method('addEventListener')->willReturnCallback(static function (array|string $names, object $listener) use (&$observers): void {
            if ($listener instanceof RunObserver) {
                $observers[] = $listener;
            }
        });

        return $events;
    }

    /**
     * @param list<RunObserver> $observers
     */
    private function load(array $observers, object $entity): void
    {
        $args = $this->createMock(LifecycleEventArgs::class);
        $args->method('getObject')->willReturn($entity);

        foreach ($observers as $observer) {
            $observer->postLoad($args);
        }
    }

    /**
     * @param array<string, array<mixed, object>> $before Identity map when the run starts
     * @param array<string, array<mixed, object>>|null $after Identity map after the failure (null = same as before)
     * @param list<object> $insertions
     */
    private function unitOfWork(array $before = [], ?array $after = null, array $insertions = []): UnitOfWork&MockObject
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getIdentityMap')->willReturnOnConsecutiveCalls($before, $after ?? $before, $after ?? $before);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn($insertions);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionDeletions')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionUpdates')->willReturn([]);

        return $unitOfWork;
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
            $unitOfWork->method('getIdentityMap')->willReturn([]);
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
