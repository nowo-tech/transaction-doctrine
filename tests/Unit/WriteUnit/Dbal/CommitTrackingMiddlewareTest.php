<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit\Dbal;

use Composer\InstalledVersions;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
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
use Nowo\TransactionDoctrine\WriteUnit\TransactionOutcome;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use stdClass;

use function str_contains;
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

        foreach (['SET autocommit = 1', 'SET SESSION autocommit = 1', '/* c */ SET @@autocommit = 1', 'SET @@SESSION.autocommit = 1'] as $statement) {
            $connection->beginTransaction();
            $connection->exec($statement); // commits the transaction (audit WD-7: it is over)
        }

        $connection->beginTransaction();
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

    /**
     * Audit WD-1: on PostgreSQL a statement that failed inside the transaction makes its COMMIT a ROLLBACK (reported as
     * a success by the driver): the transaction reads as rolled back and nothing is counted, unless a rollback to a
     * savepoint ended the failure. Other servers, and failures outside a transaction, are unaffected.
     */
    public function testAFailedStatementAbortsAPostgresTransaction(): void
    {
        $ledger       = new CommitLedger();
        $native       = null;
        $connection   = $this->connect($ledger, 'pgsql', $native);
        $transactions = $ledger->transactions($native);

        $connection->beginTransaction();
        $aborted = $transactions->current();
        $this->fails(static fn (): int|string => $connection->exec('INSERT dup'));
        $connection->commit();
        self::assertFalse($aborted?->committed(), 'PostgreSQL answered the COMMIT with a ROLLBACK');
        self::assertSame(0, $ledger->count());

        $connection->beginTransaction();
        $recovered = $transactions->current();
        $connection->exec('SAVEPOINT DOCTRINE_2');
        $this->fails(static fn (): Result => $connection->prepare('INSERT dup')->execute());
        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        $connection->commit();
        self::assertTrue($recovered?->committed(), 'the savepoint rollback ended the failure');
        self::assertSame(1, $ledger->count());

        $connection->beginTransaction();
        $rolledBack = $transactions->current();
        $this->fails(static fn (): Result => $connection->query('SELECT dup'));
        $connection->rollBack();
        $connection->beginTransaction();
        $connection->commit();
        self::assertFalse($rolledBack?->committed());
        self::assertSame(2, $ledger->count(), 'a new transaction starts clean');

        $this->fails(static fn (): int|string => $connection->exec('INSERT dup')); // outside a transaction: nothing to abort
        $connection->beginTransaction();
        $connection->commit();
        self::assertSame(3, $ledger->count());

        $ledger     = new CommitLedger();
        $connection = $this->connect($ledger, 'unknown');
        $connection->beginTransaction();
        $this->fails(static fn (): int|string => $connection->exec('INSERT dup'));
        $connection->commit();
        self::assertSame(1, $ledger->count(), 'an unknown server is not taken for PostgreSQL');

        $ledger       = new CommitLedger();
        $connection   = $this->connect($ledger, 'sqlite', $native);
        $transactions = $ledger->transactions($native);
        $connection->beginTransaction();
        $kept = $transactions->current();
        $this->fails(static fn (): int|string => $connection->exec('INSERT dup'));
        $connection->commit();
        self::assertTrue($kept?->committed(), 'only PostgreSQL aborts the transaction');
    }

    /**
     * On MySQL / MariaDB a deadlock rolls the whole transaction back at once: it is over (what follows auto-commits).
     * Audit WD-7: an implicit-commit statement (DDL) commits the open transaction.
     */
    public function testMySqlEndsTheTransactionOnDeadlockAndImplicitCommit(): void
    {
        $ledger       = new CommitLedger();
        $native       = null;
        $connection   = $this->connect($ledger, 'mysql', $native);
        $transactions = $ledger->transactions($native);

        $connection->beginTransaction();
        $deadlocked = $transactions->current();
        $this->fails(static fn (): int|string => $connection->exec('UPDATE deadlock'));
        self::assertFalse($deadlocked?->committed());
        self::assertNull($transactions->current());
        $connection->exec('INSERT INTO t VALUES (1)');
        self::assertSame(1, $ledger->count(), 'auto-committed after the deadlock');

        $connection->beginTransaction();
        $timedOut = $ledger->transactions($native)->current();
        self::assertInstanceOf(TransactionOutcome::class, $timedOut);
        $this->fails(static fn (): int|string => $connection->exec('UPDATE timeout')); // lock wait timeout: only the statement
        self::assertFalse($timedOut->hasEnded());
        $connection->exec('CREATE TABLE x (id INT)');
        self::assertTrue($timedOut->committed(), 'DDL committed the transaction');
        self::assertNull($transactions->current());
        self::assertSame(2, $ledger->count());
        $connection->exec('UPDATE t SET a = 1');
        self::assertSame(3, $ledger->count(), 'what follows auto-commits');
    }

    /** Audit WD-7: a connection lost during COMMIT leaves the outcome unknown (null), not rolled back. */
    public function testALostCommitHasAnUnknownOutcome(): void
    {
        $lost = [
            new class('SQLSTATE[08006]: connection failure', '08006') extends AbstractException {
            },
            new class('server shut down', '57P01') extends AbstractException {
            },
            new class('MySQL error', 'HY000', 2013) extends AbstractException {
            },
            new RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
            new RuntimeException('server closed the connection unexpectedly'),
        ];
        foreach ($lost as $failure) {
            self::assertTrue(CommitTrackingConnection::isConnectionLoss($failure), $failure->getMessage());
        }

        foreach ([new RuntimeException('deferred unique violation'), new class('duplicate', '23505', 1062) extends AbstractException {
        }] as $failure) {
            self::assertFalse(CommitTrackingConnection::isConnectionLoss($failure), $failure->getMessage());
        }

        $ledger = new CommitLedger();
        $driver = $this->createMock(NativeAwareDriverConnection::class);
        $driver->method('getNativeConnection')->willReturn($native = new stdClass());
        $driver->method('commit')->willThrowException($lost[0]);
        $connection = new CommitTrackingConnection($driver, $ledger);
        $connection->beginTransaction();
        $transaction = $ledger->transactions($native)->current();

        try {
            $connection->commit();
            self::fail('The commit passed.');
        } catch (AbstractException) {
        }

        self::assertNull($transaction?->committed());
        self::assertTrue($transaction?->hasEnded());
        self::assertSame(0, $ledger->count());
    }

    /** @param callable(): mixed $call */
    private function fails(callable $call): void
    {
        try {
            $call();
            self::fail('No failure.');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('failed', $failure->getMessage());
        }
    }

    /** @param-out object $native */
    private function connect(CommitLedger $ledger, string $server = 'pgsql', ?object &$native = null): DriverConnection
    {
        $fail = static function (string $sql): never {
            throw str_contains($sql, 'deadlock') ? new RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; failed', 1213) : new RuntimeException('statement failed');
        };
        $statement = $this->createMock(Statement::class);
        $statement->method('execute')->willReturn($this->createMock(Result::class));
        $failing = $this->createMock(Statement::class);
        $failing->method('execute')->willThrowException(new RuntimeException('statement failed'));
        $inner = $this->createMock(NativeAwareDriverConnection::class);
        $inner->method('query')->willReturnCallback(fn (string $sql): Result => str_contains($sql, 'dup') ? $fail($sql) : $this->createMock(Result::class));
        $inner->method('exec')->willReturnCallback(static fn (string $sql): int => str_contains($sql, 'dup') || str_contains($sql, 'deadlock') || str_contains($sql, 'timeout') ? $fail($sql) : 1);
        $inner->method('prepare')->willReturnCallback(static fn (string $sql): Statement => str_contains($sql, 'dup') ? $failing : $statement);
        if ($server === 'unknown') {
            $inner->method('getNativeConnection')->willThrowException(new RuntimeException('no native connection'));
            $native = new stdClass();
        } elseif ($server === 'mysqli') {
            $inner->method('getNativeConnection')->willReturn($native = $this->createStub(mysqli::class));
        } else {
            $pdo = $this->createMock(PDO::class);
            $pdo->method('getAttribute')->with(PDO::ATTR_DRIVER_NAME)->willReturn($server);
            $inner->method('getNativeConnection')->willReturn($pdo);
            $native = $pdo;
        }
        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($inner);

        return (new CommitTrackingMiddleware($ledger))->wrap($driver)->connect([]);
    }
}
