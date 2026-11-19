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

    /**
     * Audit UD2-L-2 (1.7): scoped to named managers, only those managers are cleared or reset; the host's default
     * manager is left alone. Leftover levels are rolled back on EVERY connection (audit UI-1).
     */
    public function testDiscardUnitOfWorkScopedToNamedManagersLeavesTheOthersAlone(): void
    {
        $hostConnection = $this->createMock(Connection::class);
        $hostConnection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(1, 1);
        $hostConnection->expects($this->once())->method('rollBack');
        $privateConnection = $this->createMock(Connection::class);
        $privateConnection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(1, 1);
        $privateConnection->expects($this->once())->method('rollBack');
        $host = $this->createMock(EntityManagerInterface::class);
        $host->method('isOpen')->willReturn(true);
        $host->expects($this->never())->method('clear');
        $private = $this->createMock(EntityManagerInterface::class);
        $private->method('isOpen')->willReturn(true);
        $private->expects($this->once())->method('clear');
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->expects($this->never())->method('clear');
        $odm = $this->createMock(ObjectManager::class);
        $odm->expects($this->once())->method('clear');
        $registry = new RecordingRegistry(
            ['default' => $hostConnection, 'private' => $privateConnection],
            ['default' => $host, 'private' => $private, 'private_audit' => $closed, 'odm' => $odm],
        );
        $logger = new RecordingLogger();

        (new DoctrineStateRepairer($registry, $logger))->discardUnitOfWork(['private', 'private_audit', 'odm']);

        self::assertSame(['private_audit'], $registry->resets);
        self::assertNotContains('warning', $logger->levels());
    }

    /** Audit UI-3 (1.7): a manager outside the scope found closed means the unit wrote through it: repair them all. */
    public function testAClosedManagerOutsideTheScopeRepairsEveryManager(): void
    {
        $host = $this->createMock(EntityManagerInterface::class);
        $host->method('isOpen')->willReturn(false);
        $other = $this->createMock(EntityManagerInterface::class);
        $other->method('isOpen')->willReturn(true);
        $other->expects($this->once())->method('clear');
        $private = $this->createMock(EntityManagerInterface::class);
        $private->method('isOpen')->willReturn(true);
        $private->expects($this->once())->method('clear');
        $registry = new RecordingRegistry([], ['default' => $host, 'other' => $other, 'private' => $private]);
        $logger   = new RecordingLogger();

        (new DoctrineStateRepairer($registry, $logger))->discardUnitOfWork(['private']);

        self::assertSame(['default'], $registry->resets);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame("A manager outside the write unit's scope was closed; repaired every manager.", $logger->records[0]['message']);
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
