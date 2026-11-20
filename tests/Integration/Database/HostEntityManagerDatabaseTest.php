<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use DomainException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Counter;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\HostManagerScenarios;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * TG delta (REQ-RUNTIME-003 rule 2) on real servers: DoctrineTransactionRunner::forHostEntityManager() never closes,
 * clears or resets the host's open manager after a failure (forEntityManager() does, for bundle-owned managers); it
 * replaces a closed one through the registry and detaches only what the failed run brought in.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class HostEntityManagerDatabaseTest extends DatabaseTestCase
{
    use HostManagerScenarios;

    #[DataProvider('allServers')]
    public function testADetachNeverCascadesIntoTheHostsEntity(string $server): void
    {
        $this->boot($server);
        $this->scenarioADetachNeverCascadesIntoTheHostsEntity();
    }

    #[DataProvider('allServers')]
    public function testARolledBackChildLeavesTheHostsCollection(string $server): void
    {
        $this->boot($server);
        $this->scenarioARolledBackChildLeavesTheHostsCollection();
    }

    #[DataProvider('allServers')]
    public function testARolledBackChildLeavesAnUninitializedCollection(string $server): void
    {
        $this->boot($server);
        $this->scenarioARolledBackChildLeavesAnUninitializedCollection();
    }

    #[DataProvider('allServers')]
    public function testTheRunsOtherCollectionChangeStays(string $server): void
    {
        $this->boot($server);
        $this->scenarioTheRunsOtherCollectionChangeStays();
    }

    #[DataProvider('allServers')]
    public function testAToOneGetsItsOriginalValueBack(string $server): void
    {
        $this->boot($server);
        $this->scenarioAToOneGetsItsOriginalValueBack();
    }

    #[DataProvider('allServers')]
    public function testAHostWithPendingChangesIsRefused(string $server): void
    {
        $this->boot($server);
        $this->scenarioAHostWithPendingChangesIsRefused();
    }

    #[DataProvider('allServers')]
    public function testAnUncomputableHostIsRefusedWithItsCause(string $server): void
    {
        $this->boot($server);
        $this->scenarioAnUncomputableHostIsRefusedWithItsCause();
    }

    #[DataProvider('allServers')]
    public function testAFieldTheRunChangedStaysWithANotice(string $server): void
    {
        $this->boot($server);
        $this->scenarioAFieldTheRunChangedStaysWithANotice();
    }

    /**
     * A storage failure (a duplicate key written through DBAL) with the manager still open: same manager, nothing
     * cleared, the host's entity kept (its edit after the failure is flushed); the run's persisted and loaded entities
     * are forgotten.
     */
    #[DataProvider('allServers')]
    public function testAStorageFailureLeavesTheHostsOpenManagerInPlace(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $host);
        $runner    = DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry);
        $persisted = new Counter(40, 4);

        $failure = $this->failure($runner, function () use ($manager, $persisted): void {
            $manager->persist($persisted);
            self::assertInstanceOf(Counter::class, $manager->find(Counter::class, 2));
            $this->connection->insert('ddrb_counter', ['id' => 1, 'counter_value' => 0]);   // duplicate key
        });

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertSame($manager, $this->manager(), 'Not reset.');
        self::assertSame(0, $this->registry->resets);
        self::assertTrue($manager->isOpen());
        self::assertTrue($manager->contains($host), 'The host entity is still managed.');
        self::assertFalse($manager->contains($persisted), 'The run\'s pending insert is forgotten.');
        self::assertFalse($manager->getUnitOfWork()->tryGetById(2, Counter::class), 'The run\'s loaded entity is forgotten.');

        $host->value = 11;
        $manager->flush();
        self::assertSame(11, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), 'The host\'s edit survives.');
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 40'), 'The failed run is not written by the host\'s flush.');

        // forEntityManager() on the same failure resets the manager: that is the bundle-owned contract.
        $this->failure(DoctrineTransactionRunner::forEntityManager($manager, $this->registry), function (): void {
            $this->connection->insert('ddrb_counter', ['id' => 1, 'counter_value' => 0]);
        });
        self::assertSame(1, $this->registry->resets);
    }

    /** A domain refusal after a flush inside the run: not cleared, and the rolled-back row is not served. */
    #[DataProvider('allServers')]
    public function testARefusalAfterAFlushDoesNotClearTheHostsManager(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $failure = $this->failure(DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry), static function () use ($manager): never {
            $manager->persist(new Counter(50));
            $manager->flush();   // written inside the runner's transaction, then rolled back

            throw new DomainException('refused after flushing');
        });

        self::assertInstanceOf(DomainException::class, $failure);
        self::assertSame($manager, $this->manager());
        self::assertTrue($manager->contains($host), 'Not cleared.');
        self::assertNull($manager->find(Counter::class, 50), 'The rolled-back row is not served from the identity map.');
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 50'));
    }

    /**
     * A real lock wait timeout in the flush: the ORM closes the manager, the host runner replaces it through the
     * registry (a closed manager is never left for the next request), and the conflict is a TransientStorageFailure.
     */
    #[DataProvider('allServers')]
    public function testAClosedHostManagerIsReplaced(string $server): void
    {
        $this->boot($server);
        $this->connection->executeStatement($this->isPostgreSql() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 7 WHERE id = 1'));
        $manager = $this->manager();
        $counter = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $counter);

        $failure = $this->failure(DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry), static function () use ($counter): void {
            $counter->value = 5;   // flushed by the runner: waits on the peer's lock
        });
        $this->otherSession()->execute('ROLLBACK');

        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertFalse($manager->isOpen(), 'The ORM closed it.');
        self::assertSame(1, $this->registry->resets);
        self::assertNotSame($manager, $this->manager());
        self::assertTrue($this->manager()->isOpen());
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * @param callable(): void $operation
     */
    private function failure(DoctrineTransactionRunner $runner, callable $operation): ?Throwable
    {
        try {
            $runner->run($operation);
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }

    #[DataProvider('allServers')]
    public function testARemovalOfTheRunsOwnEntityIsForgotten(string $server): void
    {
        $this->boot($server);
        $this->scenarioARemovalOfTheRunsOwnEntityIsForgotten();
    }

    #[DataProvider('allServers')]
    public function testARemovalOfAHostEntityStays(string $server): void
    {
        $this->boot($server);
        $this->scenarioARemovalOfAHostEntityStays();
    }
}
