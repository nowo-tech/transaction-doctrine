<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit\Dbal;

use Composer\InstalledVersions;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\DriverManager;
use mysqli;
use Nowo\TransactionDoctrine\Tests\Unit\Support\NativeAwareDriverConnection;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingConnection;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingDriver;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function str_starts_with;

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

    /**
     * Audit SL-D-L1: several statements in one string, EXPLAIN ANALYZE, and MySQL implicit commits are counted.
     */
    public function testHardenedHeuristics(): void
    {
        $ledger     = new CommitLedger();
        $connection = $this->connect($ledger, 'mysql');

        $connection->query("SELECT 1;\n");
        self::assertSame(0, $ledger->count());
        $connection->query('SELECT 1; UPDATE t SET a = 1');
        $connection->exec('EXPLAIN ANALYZE UPDATE t SET a = 1');
        $connection->exec('EXPLAIN (ANALYSE, BUFFERS) DELETE FROM t');
        self::assertSame(3, $ledger->count());
        $connection->exec('EXPLAIN SELECT 1');
        self::assertSame(3, $ledger->count());

        $connection->beginTransaction();
        $connection->exec('UPDATE t SET a = 1');
        $connection->exec('SAVEPOINT DOCTRINE_2');
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        self::assertSame(3, $ledger->count());
        $connection->exec('CREATE TABLE x (id INT)');
        $connection->exec('truncate x');
        $connection->exec('UPDATE t SET a = 1; UPDATE t SET a = 2');
        self::assertSame(6, $ledger->count());
        $connection->rollBack();
    }

    /**
     * Audit SL-2-L2: leading comments are skipped, ANALYZE anywhere in EXPLAIN's option list, every AUTOCOMMIT form.
     */
    public function testCommentsExplainOptionsAndAutocommitForms(): void
    {
        $ledger     = new CommitLedger();
        $connection = $this->connect($ledger, 'mysql');

        $connection->query("/* report */ -- line\n# hash\n  SELECT 1");
        $connection->exec('EXPLAIN (FORMAT JSON) SELECT 1');
        self::assertSame(0, $ledger->count());

        $connection->exec('/* a; b */ SELECT 1');
        $connection->exec("-- note\n/* x */ UPDATE t SET a = 1");
        $connection->exec('EXPLAIN (VERBOSE, ANALYZE) UPDATE t SET a = 1');
        $connection->exec('EXPLAIN (BUFFERS true, analyse) DELETE FROM t');
        self::assertSame(4, $ledger->count());

        $connection->beginTransaction();
        $connection->exec('SET autocommit = 1');
        $connection->exec('SET SESSION autocommit = 1');
        $connection->exec('/* c */ SET @@autocommit = 1');
        $connection->exec('SET @@SESSION.autocommit = 1');
        $connection->exec('SET @@session.sql_mode = \'\'');
        self::assertSame(8, $ledger->count());
        $connection->rollBack();
    }

    /** Audit TA3-4: WITH, VALUES and TABLE read; a WITH naming INSERT, UPDATE, DELETE or MERGE is still counted. */
    public function testReadOnlyCtesValuesAndTableAreNotCounted(): void
    {
        $ledger     = new CommitLedger();
        $connection = $this->connect($ledger, 'pgsql');

        $connection->query('WITH x AS (SELECT 1 AS a) SELECT a FROM x');
        $connection->query("with recursive n(i) as (select 1 union all select i + 1 from n where i < 3)\nselect * from n");
        $connection->query('VALUES (1), (2)');
        $connection->query('TABLE ddrb_counter');
        $connection->query('(WITH x AS (SELECT 1) SELECT * FROM x)');
        $connection->query('WITH x AS (SELECT updated_at, last_update FROM t) SELECT * FROM x');
        self::assertSame(0, $ledger->count());

        $connection->query('WITH moved AS (DELETE FROM t RETURNING *) SELECT * FROM moved');
        $connection->query("WITH x AS (SELECT 1)\nINSERT INTO t SELECT * FROM x");
        $connection->exec('WITH x AS (SELECT id FROM t) UPDATE t SET a = 1 WHERE id IN (SELECT id FROM x)');
        $connection->exec('with s as (select 1 as id) merge into t using s on t.id = s.id when matched then do nothing');
        self::assertSame(4, $ledger->count());
    }

    public function testImplicitCommitsAreMySqlOnly(): void
    {
        $servers = ['pgsql' => 0, 'mysql' => 1, 'unknown' => 1] + (class_exists(mysqli::class) ? ['mysqli' => 1] : []);
        foreach ($servers as $server => $expected) {
            $ledger     = new CommitLedger();
            $connection = $this->connect($ledger, $server);
            $connection->beginTransaction();
            $connection->exec('CREATE TABLE x (id INT)');
            $connection->exec('ALTER TABLE x ADD y INT');
            $connection->commit();
            self::assertSame($expected * 2 + 1, $ledger->count(), $server);
        }
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

    /** Audit TA2-1: a COMMIT the server refused ends the transaction for the ledger too; later autocommit writes count. */
    public function testAFailedCommitStillEndsTheTransaction(): void
    {
        $ledger  = new CommitLedger();
        $refused = new RuntimeException('deferred unique violation');
        $driver  = $this->createMock(DriverConnection::class);
        $driver->method('exec')->willReturn(1);
        $driver->method('commit')->willThrowException($refused);
        $connection = new CommitTrackingConnection($driver, $ledger);
        $connection->beginTransaction();

        try {
            $connection->commit();
            self::fail('The commit passed.');
        } catch (RuntimeException $failure) {
            self::assertSame($refused, $failure);
        }

        self::assertSame(0, $ledger->count(), 'A refused COMMIT is not a commit.');
        $connection->exec('INSERT INTO t VALUES (1)');
        self::assertSame(1, $ledger->count(), 'The autocommit write after it is counted.');
    }

    /**
     * Audit UE-CF-1 (1.7): through DBAL's own wrapper (SQLite in memory), commit() returns the driver's `true` on
     * DBAL 3 with commit tracking on (it returned null before), and nothing on DBAL 4 (void); the commit is recorded.
     */
    public function testDbalsCommitKeepsTheDriversResult(): void
    {
        $ledger        = new CommitLedger();
        $configuration = new Configuration();
        $configuration->setMiddlewares([new CommitTrackingMiddleware($ledger)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $connection->executeStatement('CREATE TABLE t (a INT)');
        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t VALUES (1)');

        $result = (new ReflectionMethod($connection, 'commit'))->invoke($connection);

        $dbal3 = str_starts_with((string) InstalledVersions::getVersion('doctrine/dbal'), '3.');
        self::assertSame($dbal3, CommitTrackingDriver::driverCommitReturnsResult());
        self::assertSame($dbal3 ? true : null, $result);
        self::assertSame(2, $ledger->count());
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM t'));
        $connection->close();
    }

    /** trackCommit() hands back what the commit returned (DBAL 3's bool) and records it once it succeeded. */
    public function testTrackCommitReturnsTheCommitsResult(): void
    {
        $ledger     = new CommitLedger();
        $connection = new CommitTrackingConnection($this->createMock(DriverConnection::class), $ledger);
        $connection->beginTransaction();

        self::assertTrue($connection->trackCommit(static fn (): bool => true));
        self::assertSame(1, $ledger->count());
        $connection->exec('INSERT INTO t VALUES (1)');
        self::assertSame(2, $ledger->count(), 'The transaction is over: the autocommit write is counted.');
    }

    private function connect(CommitLedger $ledger, string $server = 'pgsql'): DriverConnection
    {
        $statement = $this->createMock(Statement::class);
        $statement->method('execute')->willReturn($this->createMock(Result::class));
        $inner = $this->createMock(NativeAwareDriverConnection::class);
        $inner->method('query')->willReturn($this->createMock(Result::class));
        $inner->method('exec')->willReturn(1);
        $inner->method('prepare')->willReturn($statement);
        if ($server === 'unknown') {
            $inner->method('getNativeConnection')->willThrowException(new RuntimeException('no native connection'));
        } elseif ($server === 'mysqli') {
            $inner->method('getNativeConnection')->willReturn($this->createStub(mysqli::class));
        } else {
            $pdo = $this->createMock(PDO::class);
            $pdo->expects($this->atMost(1))->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn($server);
            $inner->method('getNativeConnection')->willReturn($pdo);
        }
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($inner);

        return (new CommitTrackingMiddleware($ledger))->wrap($driver)->connect([]);
    }
}
