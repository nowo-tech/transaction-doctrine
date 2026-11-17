<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit\Dbal;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingConnection;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CommitTrackingMiddlewareTest extends TestCase
{
    public function testCommitsAndWritesOutsideATransactionAreRecorded(): void
    {
        $ledger     = new CommitLedger();
        $connection = $this->connect($ledger);

        $connection->query('SELECT 1');
        $connection->exec('  SET SESSION x = 1');
        $connection->exec('(SELECT 2)');
        $connection->prepare('SHOW TABLES')->execute();
        self::assertSame(0, $ledger->count());

        $connection->exec('UPDATE t SET a = 1');
        $connection->prepare('INSERT INTO t VALUES (?)')->execute();
        $connection->query('WITH x AS (DELETE FROM t RETURNING *) SELECT * FROM x');
        self::assertSame(3, $ledger->count());

        $connection->beginTransaction();
        $connection->exec('UPDATE t SET a = 2');
        $connection->exec('SAVEPOINT DOCTRINE_2');
        $connection->prepare('DELETE FROM t')->execute();
        self::assertSame(3, $ledger->count());
        $connection->commit();
        self::assertSame(4, $ledger->count());

        $connection->beginTransaction();
        $connection->exec('UPDATE t SET a = 3');
        $connection->rollBack();
        self::assertSame(4, $ledger->count());
        $connection->exec('DELETE FROM t');
        self::assertSame(5, $ledger->count());
    }

    public function testAFailedStatementOrCommitIsNotRecorded(): void
    {
        $ledger = new CommitLedger();
        $driver = $this->createMock(DriverConnection::class);
        $driver->method('exec')->willThrowException(new RuntimeException('lock wait timeout'));
        $driver->method('commit')->willThrowException(new RuntimeException('deadlock'));
        $connection = new CommitTrackingConnection($driver, $ledger);

        foreach ([static fn (): int => $connection->exec('UPDATE t SET a = 1'), static function () use ($connection): void {
            $connection->beginTransaction();
            $connection->commit();
        }] as $call) {
            try {
                $call();
            } catch (RuntimeException) {
            }
        }

        self::assertSame(0, $ledger->count());
    }

    private function connect(CommitLedger $ledger): DriverConnection
    {
        $statement = $this->createMock(Statement::class);
        $statement->method('execute')->willReturn($this->createMock(Result::class));
        $inner = $this->createMock(DriverConnection::class);
        $inner->method('query')->willReturn($this->createMock(Result::class));
        $inner->method('exec')->willReturn(1);
        $inner->method('prepare')->willReturn($statement);
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($inner);

        return (new CommitTrackingMiddleware($ledger))->wrap($driver)->connect([]);
    }
}
