<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionDoctrine\ConnectionRepairer;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Counter;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * The DBAL behaviour the repair and the classifier rely on, checked on whichever DBAL major is installed (CI runs
 * DBAL 4 and DBAL 3): a deadlock in a nested transaction with savepoints leaves the nesting level up after the failed
 * ROLLBACK TO SAVEPOINT (1305), the ORM's 1305 carries the DeadlockException in its chain, and the repair brings the
 * connection back to level 0.
 */
final class SavepointBehaviourDatabaseTest extends DatabaseTestCase
{
    #[DataProvider('mysqlFamily')]
    public function testANestedDeadlockLeavesTheLevelUpUntilTheRepairCloses(string $server): void
    {
        $this->boot($server);
        $this->useSavepoints();
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();   // savepoint
        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
        $this->startDeadlockingPeer();
        $deadlock = null;

        try {
            $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 2');
        } catch (Throwable $throwable) {
            $deadlock = $throwable;
        }

        self::assertInstanceOf(DeadlockException::class, $deadlock);
        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));

        $lost = null;

        try {
            $this->connection->rollBack();
        } catch (Throwable $throwable) {
            $lost = $throwable;
        }

        self::assertInstanceOf(Throwable::class, $lost, 'ROLLBACK TO SAVEPOINT fails: the server dropped the savepoints.');
        self::assertSame(1305, $lost->getCode());
        self::assertNull((new TransientFailureClassifier())->classify($lost), 'A lone 1305 is not transient.');
        self::assertSame(2, $this->connection->getTransactionNestingLevel(), 'The level stays up.');

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, (new ConnectionRepairer())->afterFailure($this->connection, 0));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame('1', (string) $this->connection->fetchOne('SELECT 1'), 'The connection works again.');
    }

    #[DataProvider('mysqlFamily')]
    public function testTheOrmsFailedRollbackCarriesTheDeadlock(string $server): void
    {
        $this->boot($server);
        $this->useSavepoints();
        $failure = null;

        try {
            $this->manager()->wrapInTransaction(function (EntityManagerInterface $manager): void {
                $manager->getConnection()->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
                $this->startDeadlockingPeer();
                $counter = $manager->find(Counter::class, 2);
                self::assertInstanceOf(Counter::class, $counter);
                ++$counter->value;
                $manager->flush();   // nested: savepoint, deadlock
            });
        } catch (Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertSame('OK', $this->otherSession()->await());
        self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        self::assertSame(TransientFailureKind::Deadlock, (new TransientFailureClassifier())->classify($failure));

        (new ConnectionRepairer())->afterFailure($this->connection, 0);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * DBAL 4 always nests with savepoints; DBAL 3 does only when asked (the DBAL 3 default emulates nesting, which
     * never poisons the connection).
     */
    private function useSavepoints(): void
    {
        if (!$this->connection->getNestTransactionsWithSavepoints()) {
            $this->connection->setNestTransactionsWithSavepoints(true);
        }
    }
}
