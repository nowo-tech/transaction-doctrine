<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use Nowo\TransactionDoctrine\Tests\Unit\Support\RecordingRegistry;
use Nowo\TransactionDoctrine\WriteUnit\DoctrineStateRepairer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DoctrineStateRepairerTest extends TestCase
{
    public function testResetClosedManagersResetsOnlyClosedEntityManagers(): void
    {
        $open = $this->createMock(EntityManagerInterface::class);
        $open->method('isOpen')->willReturn(true);
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $other    = $this->createMock(ObjectManager::class);
        $registry = new RecordingRegistry([], ['default' => $open, 'audit' => $closed, 'odm' => $other]);
        $logger   = new RecordingLogger();

        self::assertSame(1, (new DoctrineStateRepairer($registry, $logger))->resetClosedManagers());
        self::assertSame(['audit'], $registry->resets);
        self::assertSame('Reset closed entity managers.', $logger->records[0]['message']);
        self::assertSame(0, (new DoctrineStateRepairer(new RecordingRegistry([], ['default' => $open])))->resetClosedManagers());
    }

    public function testDiscardUnitOfWorkRollsBackClearsOpenManagersAndResetsClosedOnes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(1, 1);
        $connection->expects($this->once())->method('rollBack');
        $open = $this->createMock(EntityManagerInterface::class);
        $open->method('isOpen')->willReturn(true);
        $open->expects($this->once())->method('clear');
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects($this->never())->method('clear');
        $odm = $this->createMock(ObjectManager::class);
        $odm->expects($this->once())->method('clear');
        $registry = new RecordingRegistry(['default' => $connection, 'not_dbal' => new stdClass()], ['default' => $open, 'audit' => $closed, 'odm' => $odm]);

        (new DoctrineStateRepairer($registry))->discardUnitOfWork();

        self::assertSame(['audit'], $registry->resets);
    }

    public function testAfterDatabaseFailurePingsConnectedConnectionsOnly(): void
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getDummySelectSQL')->willReturn('SELECT 1');
        $connected = $this->createMock(Connection::class);
        $connected->method('isConnected')->willReturn(true);
        $connected->method('getDatabasePlatform')->willReturn($platform);
        $connected->expects($this->once())->method('executeQuery')->with('SELECT 1');
        $connected->expects($this->never())->method('close');
        $idle = $this->createMock(Connection::class);
        $idle->method('isConnected')->willReturn(false);
        $idle->expects($this->never())->method('executeQuery');

        (new DoctrineStateRepairer(new RecordingRegistry(['default' => $connected, 'idle' => $idle])))->afterDatabaseFailure();
    }

    public function testAConnectionThatNoLongerAnswersIsClosed(): void
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getDummySelectSQL')->willReturn('SELECT 1');
        $lost = $this->createMock(Connection::class);
        $lost->method('isConnected')->willReturn(true);
        $lost->method('getDatabasePlatform')->willReturn($platform);
        $lost->method('executeQuery')->willThrowException(new RuntimeException('MySQL server has gone away'));
        $lost->expects($this->once())->method('close');
        $logger = new RecordingLogger();

        (new DoctrineStateRepairer(new RecordingRegistry(['default' => $lost]), $logger))->afterDatabaseFailure();

        self::assertSame('Database connection lost after a failure: closed, the next query reconnects.', $logger->records[0]['message']);
        self::assertSame(RuntimeException::class, $logger->records[0]['context']['exception_class']);
    }
}
