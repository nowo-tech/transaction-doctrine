<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests;

use Doctrine\DBAL\Connection;
use Nowo\TransactionDoctrine\ConnectionRepairer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConnectionRepairerTest extends TestCase
{
    public function testAfterFailureLeavesAConnectionAtTheEntryLevelAlone(): void
    {
        $connection = $this->connectionAt(1);
        $connection->expects($this->never())->method('close');
        $connection->expects($this->never())->method('rollBack');

        self::assertSame(ConnectionRepairer::ACTION_NONE, (new ConnectionRepairer())->afterFailure($connection, 1));
    }

    public function testAfterFailureAtEntryLevelZeroRollsBackFirst(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(1, 1);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_ROLLED_BACK, (new ConnectionRepairer())->afterFailure($connection, 0));
    }

    public function testAfterFailureAtEntryLevelZeroClosesWhenTheSavepointRollbackFails(): void
    {
        $connection = $this->connectionAt(2);
        $connection->expects($this->once())->method('rollBack')->willThrowException(new RuntimeException('1305 SAVEPOINT DOCTRINE_2 does not exist'));
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->afterFailure($connection, 0));
    }

    public function testAfterFailureInsideACallersTransactionRollsBackToItsLevel(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(3, 3);
        $connection->expects($this->exactly(2))->method('rollBack');
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_ROLLED_BACK, (new ConnectionRepairer())->afterFailure($connection, 1));
    }

    public function testAfterFailureClosesWhenTheRollbackFails(): void
    {
        $connection = $this->connectionAt(2);
        $connection->method('rollBack')->willThrowException(new RuntimeException('1305 SAVEPOINT DOCTRINE_2 does not exist'));
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->afterFailure($connection, 1));
    }

    public function testBetweenRequestsLeavesACleanConnectionAlone(): void
    {
        $connection = $this->connectionAt(0);
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_NONE, (new ConnectionRepairer())->betweenRequests($connection));
    }

    public function testBetweenRequestsRollsBackASingleLevel(): void
    {
        $connection = $this->connectionAt(1);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_ROLLED_BACK, (new ConnectionRepairer())->betweenRequests($connection));
    }

    public function testBetweenRequestsClosesWhenTheSingleLevelRollbackFails(): void
    {
        $connection = $this->connectionAt(1);
        $connection->method('rollBack')->willThrowException(new RuntimeException('gone away'));
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->betweenRequests($connection));
    }

    public function testBetweenRequestsClosesANestedLeftover(): void
    {
        $connection = $this->connectionAt(2);
        $connection->expects($this->never())->method('rollBack');
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->betweenRequests($connection));
    }

    public function testBetweenRequestsHonoursTheAutoCommitOffBaseline(): void
    {
        $connection = $this->connectionAt(2);
        $connection->expects($this->once())->method('rollBack');
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_ROLLED_BACK, (new ConnectionRepairer())->betweenRequests($connection, 1));
    }

    public function testWithEmulatedNestingANestedLeftoverIsRolledBackLevelByLevel(): void
    {
        $level      = 3;
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $connection->expects($this->exactly(3))->method('rollBack')->willReturnCallback(static function () use (&$level): void {
            --$level;
        });
        $connection->expects($this->never())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_ROLLED_BACK, (new ConnectionRepairer(true))->betweenRequests($connection));
    }

    public function testWithEmulatedNestingAClosedConnectionHasItsRollbackOnlyFlagCleared(): void
    {
        $connection = $this->connectionAt(1);
        $calls      = 0;
        $connection->method('rollBack')->willReturnCallback(static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new RuntimeException('server has gone away');
            }
        });
        $connection->expects($this->once())->method('close');
        $connection->expects($this->once())->method('beginTransaction');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(true))->afterFailure($connection, 0));
        self::assertSame(2, $calls, 'Begin and roll back once more to clear the flag.');
    }

    public function testClearingTheFlagToleratesADeadServer(): void
    {
        $connection = $this->connectionAt(1);
        $connection->method('rollBack')->willThrowException(new RuntimeException('server has gone away'));
        $connection->method('beginTransaction')->willThrowException(new RuntimeException('connection refused'));
        $connection->expects($this->once())->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(true))->afterFailure($connection, 0));
    }

    public function testWithoutEmulatedNestingANestedLeftoverIsClosed(): void
    {
        $connection = $this->connectionAt(3);
        $connection->expects($this->once())->method('close');
        $connection->expects($this->never())->method('beginTransaction');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(false))->betweenRequests($connection));
    }

    private function connectionAt(int $level): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn($level);
        // DBAL 3 legs: the mock would otherwise report emulated nesting.
        $connection->method('getNestTransactionsWithSavepoints')->willReturn(true);

        return $connection;
    }
}
