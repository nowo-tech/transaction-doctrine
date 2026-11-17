<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use DomainException;
use Exception;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConnectionRepairer;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use stdClass;
use Stringable;
use Throwable;

use function json_encode;

/**
 * REQ-OBS-001: optional PSR-3 logging with structured context and no SQL, parameters or messages.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LoggingTest extends TestCase
{
    public function testRepairerLogsTheRolledBackLevelsAtNotice(): void
    {
        $logger     = $this->recorder();
        $connection = $this->connection([3, 3]);

        (new ConnectionRepairer(null, $logger))->afterFailure($connection, 1);

        self::assertSame('notice', $logger->records[0]['level']);
        self::assertSame(['from_level' => 3, 'to_level' => 1, 'action' => 'rolled_back'], $logger->records[0]['context']);
    }

    public function testRepairerLogsTheCloseAtWarningWithoutTheMessage(): void
    {
        $logger     = $this->recorder();
        $connection = $this->connection([2, 2]);
        $connection->method('rollBack')->willThrowException(new RuntimeException('1305 SELECT secret FROM t WHERE p = \'hunter2\'', 1305));
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(null, $logger))->afterFailure($connection, 0));

        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception_class']);
        self::assertSame(1305, $logger->records[0]['context']['exception_code']);
        self::assertStringNotContainsString('hunter2', $this->dump($logger));
        self::assertStringNotContainsString('SELECT', $this->dump($logger));
    }

    public function testRepairerLogsTheCloseBetweenRequests(): void
    {
        $logger     = $this->recorder();
        $connection = $this->connection([3]);
        $connection->expects($this->once())->method('close');

        (new ConnectionRepairer(false, $logger))->betweenRequests($connection);

        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['nesting_level' => 3, 'baseline' => 0, 'action' => 'closed'], $logger->records[0]['context']);
    }

    public function testRepairerLogsAFailedRollbackOnlyClearAtDebug(): void
    {
        $logger     = $this->recorder();
        $connection = $this->connection([1, 1]);
        $connection->method('rollBack')->willThrowException(new RuntimeException('gone'));
        $connection->method('beginTransaction')->willThrowException(new RuntimeException('gone'));

        (new ConnectionRepairer(true, $logger))->afterFailure($connection, 0);

        self::assertSame(['warning', 'debug'], $logger->levels());
    }

    public function testRunnerLogsATransientFailureAtNoticeAndTheManagerResetAtNotice(): void
    {
        $logger     = $this->recorder();
        $connection = $this->connection([0, 2, 2]);
        $connection->method('rollBack')->willThrowException(new LogicException('1305'));
        $manager = $this->manager($connection, false);
        $manager->expects($this->once())->method('close');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $registry->expects($this->once())->method('resetManager')->with('orders');
        $deadlock = $this->deadlock();

        $caught = $this->failureOf(static fn () => DoctrineTransactionRunner::forEntityManager($manager, $registry, 'orders', 3, $logger)->run(static function () use ($deadlock): never {
            throw $deadlock;
        }));

        self::assertInstanceOf(TransientStorageFailure::class, $caught);

        self::assertSame(['notice', 'warning', 'notice'], $logger->levels());
        self::assertTrue($logger->records[0]['context']['transient']);
        self::assertSame(DeadlockException::class, $logger->records[0]['context']['exception_class']);
        self::assertSame('orders', $logger->records[2]['context']['manager']);
        self::assertStringNotContainsString('SELECT', $this->dump($logger));
    }

    public function testRunnerLogsADomainFailureAtDebugAndTheClearAtDebug(): void
    {
        $logger     = $this->recorder();
        $connection = $this->connection([0, 1, 1]);
        $manager    = $this->manager($connection, true, true);
        $manager->expects($this->once())->method('clear');

        $caught = $this->failureOf(static fn () => DoctrineTransactionRunner::forEntityManager($manager, null, null, 1, $logger)->run(static function (): never {
            throw new DomainException('refused');
        }));

        self::assertInstanceOf(DomainException::class, $caught);

        self::assertSame(['debug', 'notice', 'debug'], $logger->levels());
        self::assertFalse($logger->records[0]['context']['transient']);
    }

    public function testConnectionRunnerAcceptsALoggerAndSucceedsSilently(): void
    {
        $logger     = $this->recorder();
        $connection = $this->connection([0]);

        $result = DoctrineTransactionRunner::forConnection($connection, 1, $logger)->run(static fn (): string => uniqid('r', true));

        self::assertStringStartsWith('r', $result);
        self::assertSame([], $logger->records);
    }

    public function testALoggerThatThrowsNeverReplacesTheOriginalFailure(): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger is down');
            }
        };
        $connection = $this->connection([0, 1, 1]);
        $refusal    = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forConnection($connection, 1, $logger)->run(static function () use ($refusal): never {
            throw $refusal;
        });
    }

    public function testTheLoggerIsOptional(): void
    {
        $connection = $this->connection([0, 1, 1]);
        $refusal    = new DomainException('refused');

        $this->expectExceptionObject($refusal);

        DoctrineTransactionRunner::forConnection($connection)->run(static function () use ($refusal): never {
            throw $refusal;
        });
    }

    /**
     * @param list<int> $levels
     */
    private function connection(array $levels): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(...$levels);
        $connection->method('getNestTransactionsWithSavepoints')->willReturn(true);

        return $connection;
    }

    private function manager(Connection $connection, bool $open, bool $pending = false): EntityManagerInterface&MockObject
    {
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn($pending ? [new stdClass()] : []);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([]);
        $unitOfWork->method('getScheduledEntityDeletions')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionDeletions')->willReturn([]);
        $unitOfWork->method('getScheduledCollectionUpdates')->willReturn([]);
        $unitOfWork->method('getIdentityMap')->willReturn([]);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn($open);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->method('getEventManager')->willReturn($this->createMock(EventManager::class));

        return $manager;
    }

    private function deadlock(): DeadlockException
    {
        $driver = new class('Deadlock found: SELECT secret FROM t WHERE p = \'hunter2\'', 1213) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return '40001';
            }
        };

        return new DeadlockException($driver, null);
    }

    private function failureOf(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function recorder(): RecordingLogger
    {
        return new RecordingLogger();
    }

    private function dump(RecordingLogger $logger): string
    {
        return (string) json_encode($logger->records);
    }
}
