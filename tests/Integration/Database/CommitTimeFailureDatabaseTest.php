<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use Throwable;

/**
 * Audit TA3-5 on PostgreSQL 16: a COMMIT the server refuses. On DBAL 3 the failure is a Driver\PDO\PDOException whose
 * getCode() is the SQLSTATE string; before 1.6.0 the classifier passed it to an int parameter and the TypeError lost
 * the failure (no repair, no replay). A SERIALIZABLE write skew refused at COMMIT (40001) becomes the contract's
 * TransientStorageFailure and is replayed by a write unit; a deferred unique violation at COMMIT (23505) is rethrown
 * unchanged. The connection is back at level 0 and usable each time. Runs on every CI leg, DBAL 3 included.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CommitTimeFailureDatabaseTest extends DatabaseTestCase
{
    public function testASerializationFailureAtCommitBecomesTransientStorageFailure(): void
    {
        $this->boot('TD_POSTGRES_URL');

        $failure = $this->failure(fn () => $this->writeSkew(true));

        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertSame(TransientFailureKind::SerializationFailure, (new TransientFailureClassifier())->classify($failure));
        $this->assertUsable();
        self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), 'Rolled back.');
    }

    public function testAWriteUnitReplaysASerializationFailureAtCommit(): void
    {
        $this->boot('TD_POSTGRES_URL');
        $unit   = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0));
        $runner = DoctrineTransactionRunner::forConnection($this->connection);

        $attempts = $unit->run(function (int $attempt) use ($runner): int {
            $runner->run(fn () => $this->writeSkew($attempt === 1));

            return $attempt;
        });

        self::assertSame(2, $attempts);
        $this->assertUsable();
        self::assertSame(1, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), 'The replay committed once.');
    }

    public function testADeferredUniqueViolationAtCommitIsRethrownUnchanged(): void
    {
        $this->boot('TD_POSTGRES_URL');
        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_deferred');
        $this->connection->executeStatement('CREATE TABLE ddrb_deferred (id INT, CONSTRAINT ddrb_deferred_id UNIQUE (id) DEFERRABLE INITIALLY DEFERRED)');

        try {
            $failure = $this->failure(function (): void {
                $this->connection->executeStatement('INSERT INTO ddrb_deferred VALUES (1), (1)');   // checked at COMMIT
            });

            self::assertInstanceOf(UniqueConstraintViolationException::class, $failure);
            self::assertNull((new TransientFailureClassifier())->classify($failure));
            $this->assertUsable();
            self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_deferred'));
        } finally {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_deferred');
        }
    }

    /**
     * Inside the test connection's SERIALIZABLE transaction: read both counters, write counter 1. With $conflict the
     * peer reads both, writes counter 2 and commits first: the test transaction's COMMIT is then refused (40001).
     */
    private function writeSkew(bool $conflict): void
    {
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $this->connection->fetchOne('SELECT SUM(counter_value) FROM ddrb_counter');
        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');

        if ($conflict) {
            $peer = $this->otherSession();
            self::assertSame('OK', $peer->execute('BEGIN ISOLATION LEVEL SERIALIZABLE'));
            self::assertSame('OK', $peer->execute('SELECT SUM(counter_value) FROM ddrb_counter'));
            self::assertSame('OK', $peer->execute('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 2'));
            self::assertSame('OK', $peer->execute('COMMIT'));
        }
    }

    /**
     * @param callable(): void $operation
     */
    private function failure(callable $operation): ?Throwable
    {
        try {
            DoctrineTransactionRunner::forConnection($this->connection)->run($operation);
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }

    private function assertUsable(): void
    {
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT 1'));
    }
}
