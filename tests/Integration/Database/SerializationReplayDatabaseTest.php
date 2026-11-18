<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function json_decode;

/**
 * Since 1.5.0 a write unit replays a serialization failure by default (owner decision 2026-11-18): PostgreSQL 40001
 * under REPEATABLE READ, MariaDB 1020 with innodb_snapshot_isolation. A real one is replayed and the replay commits;
 * when every attempt fails the unit gives up with ConcurrencyConflict, answered 409 CONFLICT_001 + Retry-After by the
 * default responder. An explicit `replay_on` without `serialization_failure` keeps the 1.4 behaviour: the failure is
 * rethrown at once and the default responder does not answer it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SerializationReplayDatabaseTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function serializationServers(): iterable
    {
        yield 'MariaDB 11.4 (1020)' => ['TD_MARIADB_URL'];
        yield 'PostgreSQL 16 (40001)' => ['TD_POSTGRES_URL'];
    }

    #[DataProvider('serializationServers')]
    public function testASerializationFailureIsReplayedByDefaultAndTheReplayCommits(string $server): void
    {
        $this->prepare($server);
        $unit     = new WriteUnit($this->registry, new WriteUnitPolicy(floorMs: 0, baseMs: 0, capMs: 0));
        $failures = [];

        $result = $unit->run(function (int $attempt, ?Throwable $previous) use (&$failures): int {
            if ($previous instanceof Throwable) {
                $failures[] = (new TransientFailureClassifier())->classify($previous);
            }

            $this->increment($attempt === 1);

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame([TransientFailureKind::SerializationFailure], $failures);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(101, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), 'The peer\'s +100 and exactly one +1.');
    }

    #[DataProvider('serializationServers')]
    public function testASerializationFailureOnEveryAttemptAnswers409Conflict001(string $server): void
    {
        $this->prepare($server);
        $unit     = new WriteUnit($this->registry, new WriteUnitPolicy(2, 0, 0, 0));
        $attempts = 0;
        $caught   = null;

        try {
            $unit->run(function () use (&$attempts): void {
                ++$attempts;
                $this->increment(true);
            });
        } catch (ConcurrencyConflict $conflict) {
            $caught = $conflict;
        }

        self::assertSame(2, $attempts);
        self::assertInstanceOf(ConcurrencyConflict::class, $caught);
        self::assertSame(TransientFailureKind::SerializationFailure, $caught->kind);
        self::assertFalse($caught->committed);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(200, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), 'Only the peer\'s writes.');

        $response = (new ConcurrencyConflictResponder(new ConflictResponsePolicy()))->respond(Request::create('/api/counter', 'POST'), $caught);
        $body     = json_decode((string) $response->getContent(), true);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertIsArray($body);
        self::assertSame(ConflictCode::RETRY_SAFE, $body['code']);
        self::assertSame('concurrency_conflict', $body['error']);
    }

    #[DataProvider('serializationServers')]
    public function testAnExplicitReplayOnWithoutItKeepsTheOldBehaviour(string $server): void
    {
        $this->prepare($server);
        $unit     = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0, ['deadlock', 'lock_wait_timeout']));
        $attempts = 0;
        $caught   = null;

        try {
            $unit->run(function () use (&$attempts): void {
                ++$attempts;
                $this->increment(true);
            });
        } catch (Throwable $failure) {
            $caught = $failure;
        }

        self::assertSame(1, $attempts);
        self::assertInstanceOf(Throwable::class, $caught);
        self::assertNotInstanceOf(ConcurrencyConflict::class, $caught);
        self::assertSame(TransientFailureKind::SerializationFailure, (new TransientFailureClassifier())->classify($caught));
        self::assertFalse((new ConcurrencyConflictResponder(new ConflictResponsePolicy()))->isConflict($caught));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    private function prepare(string $server): void
    {
        $this->boot($server);
        if (!$this->isPostgreSql()) {
            $this->connection->executeStatement('SET SESSION innodb_snapshot_isolation = ON');
            $this->connection->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
    }

    /**
     * One counter increment through the runner. With $conflict a peer commits +100 on the row after this
     * transaction's snapshot was taken, so the update fails with a serialization failure.
     */
    private function increment(bool $conflict): void
    {
        DoctrineTransactionRunner::forConnection($this->connection)->run(function () use ($conflict): void {
            if ($this->isPostgreSql()) {
                $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            $this->connection->fetchOne('SELECT counter_value FROM ddrb_counter WHERE id = 1');
            if ($conflict) {
                self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = counter_value + 100 WHERE id = 1'));
            }

            $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
        });
    }
}
