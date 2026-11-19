<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Nowo\TransactionDoctrine\ConnectionRepairer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function version_compare;

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
        // The double keeps reporting level 1 after the failed BEGIN: rolled back (fails), then closed once more (1.7.2).
        $connection->expects($this->exactly(2))->method('close');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(true))->afterFailure($connection, 0));
    }

    public function testWithoutEmulatedNestingANestedLeftoverIsClosed(): void
    {
        $connection = $this->connectionAt(3);
        $connection->expects($this->once())->method('close');
        $connection->expects($this->never())->method('beginTransaction');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(false))->betweenRequests($connection));
    }

    /** 1.7.2: a closed connection whose transaction was rollback-only has the flag cleared, savepoints or not. */
    public function testARollbackOnlyConnectionHasItsFlagClearedAfterTheClose(): void
    {
        $journal    = [];
        $connection = $this->leveledConnection(2, $journal, failFirstRollback: true);
        $connection->method('isRollbackOnly')->willReturn(true);

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(false))->afterFailure($connection, 0));
        self::assertSame(['rollBack!', 'close', 'begin', 'rollBack'], $journal);
    }

    /** 1.7.2: an unreadable flag counts as set (clearing it is harmless). */
    public function testAnUnreadableFlagIsCleared(): void
    {
        $journal    = [];
        $connection = $this->leveledConnection(3, $journal);
        $connection->method('isRollbackOnly')->willThrowException(new RuntimeException('no active transaction'));

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(false))->betweenRequests($connection));
        self::assertSame(['close', 'begin', 'rollBack'], $journal);
    }

    /**
     * Audit VQ-6: with auto-commit disabled the reconnect inside BEGIN opens level 1 by itself, so BEGIN only adds a
     * savepoint. Both levels are rolled back (the level-1 ROLLBACK is the one that clears the flag).
     */
    public function testWithAutoCommitOffBothLevelsAreRolledBack(): void
    {
        $journal    = [];
        $connection = $this->leveledConnection(3, $journal, autoCommitOff: true);
        $connection->method('isRollbackOnly')->willReturn(true);

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(false))->betweenRequests($connection, 1));
        self::assertSame(['close', 'begin', 'rollBack', 'rollBack'], $journal);
    }

    /** 1.7.2: a BEGIN that reached the driver and failed leaves a level: it is rolled back, else closed again. */
    public function testAFailedBeginWhileClearingTheFlagDropsItsLevel(): void
    {
        foreach ([false, true] as $rollbackFails) {
            $level      = 3;
            $connection = $this->createMock(Connection::class);
            $connection->method('getNestTransactionsWithSavepoints')->willReturn(true);
            $connection->method('isRollbackOnly')->willReturn(true);
            $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
                return $level;
            });
            $connection->method('close')->willReturnCallback(static function () use (&$level): void {
                $level = 0;
            });
            $connection->method('beginTransaction')->willReturnCallback(static function () use (&$level): never {
                $level = 1;

                throw new RuntimeException('connection refused');
            });
            $connection->expects($this->once())->method('rollBack')->willReturnCallback(static function () use (&$level, $rollbackFails): void {
                $level = 0;
                if ($rollbackFails) {
                    throw new RuntimeException('server has gone away');
                }
            });
            $connection->expects($this->exactly($rollbackFails ? 2 : 1))->method('close');

            self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer(false))->betweenRequests($connection));
            self::assertSame(0, $level);
        }
    }

    /**
     * DBAL 3 declares getTransactionNestingLevel() without a native return type, so a test double returns null
     * (ChatKit's unit tests): no TypeError, nothing to repair (1.7.2).
     */
    public function testADbal3DoubleWithoutANestingLevelIsLeftAlone(): void
    {
        if (version_compare((string) InstalledVersions::getVersion('doctrine/dbal'), '4.0.0', '>=')) {
            self::markTestSkipped('DBAL 4 declares int: a double cannot return null.');
        }

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('rollBack');

        self::assertSame(ConnectionRepairer::ACTION_NONE, (new ConnectionRepairer())->afterFailure($connection, 0));
        self::assertSame(ConnectionRepairer::ACTION_NONE, (new ConnectionRepairer())->betweenRequests($connection));
    }

    /**
     * A double that keeps its nesting level like DBAL: BEGIN adds one (two after a close with auto-commit off: the
     * reconnect opens level 1), ROLLBACK drops one, close() resets it.
     *
     * @param list<string> $journal
     */
    private function leveledConnection(int $level, array &$journal, bool $failFirstRollback = false, bool $autoCommitOff = false): Connection&MockObject
    {
        $closed     = false;
        $connection = $this->createMock(Connection::class);
        $connection->method('getNestTransactionsWithSavepoints')->willReturn(true);
        $connection->method('getTransactionNestingLevel')->willReturnCallback(static function () use (&$level): int {
            return $level;
        });
        $connection->method('close')->willReturnCallback(static function () use (&$level, &$closed, &$journal): void {
            $journal[] = 'close';
            $level     = 0;
            $closed    = true;
        });
        $connection->method('beginTransaction')->willReturnCallback(static function () use (&$level, &$closed, &$journal, $autoCommitOff): void {
            $journal[] = 'begin';
            $level += $closed && $autoCommitOff ? 2 : 1;
            $closed = false;
        });
        $connection->method('rollBack')->willReturnCallback(static function () use (&$level, &$journal, &$failFirstRollback): void {
            if ($failFirstRollback) {
                $failFirstRollback = false;
                $journal[]         = 'rollBack!';

                throw new RuntimeException('1305 SAVEPOINT DOCTRINE_2 does not exist');
            }

            $journal[] = 'rollBack';
            --$level;
        });

        return $connection;
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
