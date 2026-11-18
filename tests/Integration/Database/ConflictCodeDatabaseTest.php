<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\Conflict\TransientFailureConflictMapper;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function json_decode;

/**
 * REQ-RUNTIME-003 rule 6 on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): which real errors are conflicts,
 * which code they answer, and that unique violations and lost connections are never mapped. The responder runs with
 * `map_transient_failures` (TransientFailureConflictMapper), the path a raw driver error takes to the kernel.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConflictCodeDatabaseTest extends DatabaseTestCase
{
    /** A lock wait timeout (MySQL / MariaDB 1205, PostgreSQL 55P03): CONFLICT_001 with Retry-After. */
    #[DataProvider('allServers')]
    public function testALockWaitTimeoutAnswersConflict001(string $server): void
    {
        $this->boot($server);
        $this->connection->executeStatement($this->isPostgreSql() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 9 WHERE id = 1'));

        $failure = $this->failure('UPDATE ddrb_counter SET counter_value = 5 WHERE id = 1');
        $this->otherSession()->execute('ROLLBACK');

        self::assertSame(TransientFailureKind::LockWaitTimeout, (new TransientFailureClassifier())->classify($failure));
        $this->assertAnswer($failure, ConflictCode::RetrySafe, '1');
    }

    /** A MySQL / MariaDB deadlock (1213) on the victim: CONFLICT_001. */
    #[DataProvider('mysqlFamily')]
    public function testADeadlockAnswersConflict001(string $server): void
    {
        $this->boot($server);
        $this->connection->beginTransaction();
        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
        $this->startDeadlockingPeer();

        $failure = $this->failure('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 2');
        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        $this->connection->rollBack();

        self::assertSame(1213, $failure->getCode());
        self::assertSame(TransientFailureKind::Deadlock, (new TransientFailureClassifier())->classify($failure));
        $this->assertAnswer($failure, ConflictCode::RetrySafe, '1');
    }

    /** A PostgreSQL serialization failure (40001) under REPEATABLE READ: CONFLICT_001. */
    public function testAPostgreSqlSerializationFailureAnswersConflict001(): void
    {
        $this->boot('TD_POSTGRES_URL');
        $this->connection->beginTransaction();
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->connection->fetchOne('SELECT counter_value FROM ddrb_counter WHERE id = 1');
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = counter_value + 100 WHERE id = 1'));

        $failure = $this->failure('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
        $this->connection->rollBack();

        self::assertSame(TransientFailureKind::SerializationFailure, (new TransientFailureClassifier())->classify($failure));
        $this->assertAnswer($failure, ConflictCode::RetrySafe, '1');
    }

    /**
     * A savepoint the server no longer knows (MySQL / MariaDB 1305, PostgreSQL 3B001) with no deadlock behind it: the
     * outcome of what ran before is unknown, so CONFLICT_002 and no Retry-After.
     */
    #[DataProvider('allServers')]
    public function testALostSavepointAnswersConflict002(string $server): void
    {
        $this->boot($server);
        $this->connection->beginTransaction();

        $failure = $this->failure('ROLLBACK TO SAVEPOINT td_missing');
        $this->connection->rollBack();

        $classifier = new TransientFailureClassifier();
        self::assertNull($classifier->classify($failure));
        self::assertTrue($classifier->isCommitOutcomeUnknown($failure));
        $this->assertAnswer($failure, ConflictCode::Committed, null);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /** MySQL / MariaDB report a missing function with 1305 too: that is a bug, never a conflict. */
    #[DataProvider('mysqlFamily')]
    public function testAMissingFunctionIsNotALostSavepoint(string $server): void
    {
        $this->boot($server);

        $failure = $this->failure('SELECT td_missing_function()');

        self::assertSame(1305, $failure->getCode());
        $this->assertNotMapped($failure);
    }

    #[DataProvider('allServers')]
    public function testAUniqueViolationIsNeverMapped(string $server): void
    {
        $this->boot($server);

        $this->assertNotMapped($this->failure('INSERT INTO ddrb_counter (id, counter_value) VALUES (1, 0)'));
    }

    /** The server killed the session (lost connection): never a conflict, its outcome is unknown to the driver. */
    #[DataProvider('allServers')]
    public function testALostConnectionIsNeverMapped(string $server): void
    {
        $this->boot($server);
        $id = (string) $this->connection->fetchOne($this->isPostgreSql() ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()');
        self::assertSame('OK', $this->otherSession()->execute($this->isPostgreSql() ? 'SELECT pg_terminate_backend(' . $id . ')' : 'KILL ' . $id));

        $this->assertNotMapped($this->failure('SELECT 1'));
    }

    private function failure(string $sql): Throwable
    {
        try {
            $this->connection->executeStatement($sql);
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('The statement did not fail: ' . $sql);
    }

    private function assertAnswer(Throwable $failure, ConflictCode $code, ?string $retryAfter): void
    {
        $responder = new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [new TransientFailureConflictMapper()]);
        self::assertTrue($responder->isConflict($failure));
        self::assertSame($code, ConflictCode::forFailure($failure));

        $response = $responder->respond(Request::create('/api/notes', 'POST'), $failure);
        $body     = json_decode((string) $response->getContent(), true);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame($retryAfter, $response->headers->get('Retry-After'));
        self::assertIsArray($body);
        self::assertSame($code->value, $body['code']);
        self::assertSame($code === ConflictCode::Committed ? 'concurrency_conflict_partial' : 'concurrency_conflict', $body['error']);
    }

    private function assertNotMapped(Throwable $failure): void
    {
        $classifier = new TransientFailureClassifier();
        self::assertNull($classifier->classify($failure));
        self::assertFalse($classifier->isCommitOutcomeUnknown($failure));
        self::assertFalse((new TransientFailureConflictMapper())->isConflict($failure));
        self::assertFalse((new ConcurrencyConflictResponder(new ConflictResponsePolicy(), [new TransientFailureConflictMapper()]))->isConflict($failure));
    }
}
