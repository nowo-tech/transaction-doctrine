<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Database;

use DomainException;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Database\Support\Counter;
use Nowo\TransactionDoctrine\Tests\Database\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * What a failed run() does to a shared, host-owned entity manager (ChatKit audit QD-M1): a domain refusal that left
 * nothing pending keeps the host's entities managed; one that changed or flushed something clears the manager
 * (same instance, nothing pending); a storage failure resets it.
 */
final class ManagerSemanticsDatabaseTest extends DatabaseTestCase
{
    #[DataProvider('allServers')]
    public function testADomainRefusalKeepsTheHostsEntitiesManaged(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 1);   // loaded by the host before calling the runner
        self::assertInstanceOf(Counter::class, $host);
        $runner = DoctrineTransactionRunner::forEntityManager($manager, $this->registry);

        $failure = $this->failure($runner, static function (): never {
            throw new DomainException('refused');
        });

        self::assertInstanceOf(DomainException::class, $failure);
        self::assertSame($manager, $this->manager(), 'Not reset.');
        self::assertTrue($manager->contains($host), 'The host entity is still managed.');

        $host->value = 7;
        $manager->flush();
        self::assertSame(7, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
    }

    #[DataProvider('allServers')]
    public function testADomainFailureAfterTheOperationChangedAnEntityClearsTheManager(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $runner  = DoctrineTransactionRunner::forEntityManager($manager, $this->registry);

        $failure = $this->failure($runner, static function () use ($manager): never {
            $counter = $manager->find(Counter::class, 1);
            self::assertInstanceOf(Counter::class, $counter);
            $counter->value = 99;   // changed, not flushed

            throw new DomainException('refused');
        });

        self::assertInstanceOf(DomainException::class, $failure);
        self::assertSame($manager, $this->manager(), 'Cleared, not reset.');
        $manager->flush();
        self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), 'The change was discarded.');
    }

    #[DataProvider('allServers')]
    public function testADomainFailureAfterAFlushInsideTheOperationClearsTheStaleIdentityMap(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $runner  = DoctrineTransactionRunner::forEntityManager($manager, $this->registry);

        $failure = $this->failure($runner, static function () use ($manager): never {
            $manager->persist(new Counter(50));
            $manager->flush();   // written inside the runner's transaction, then rolled back

            throw new DomainException('refused after flushing');
        });

        self::assertInstanceOf(DomainException::class, $failure);
        self::assertNull($manager->find(Counter::class, 50), 'The rolled-back row is not served from a stale identity map.');
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 50'));
    }

    #[DataProvider('allServers')]
    public function testAStorageFailureResetsTheManager(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $runner  = DoctrineTransactionRunner::forEntityManager($manager, $this->registry);

        $failure = $this->failure($runner, function (): void {
            $this->connection->insert('ddrb_counter', ['id' => 1, 'counter_value' => 0]);   // duplicate key
        });

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertNotSame($manager, $this->manager(), 'Reset through the registry.');
        self::assertTrue($this->manager()->isOpen());
    }

    /**
     * QA-TD11-M1 [6]: a row the run wrote through DBAL and then loaded is not served from the identity map after the
     * rollback.
     */
    #[DataProvider('allServers')]
    public function testARefusalForgetsARowTheRunInsertedAndLoaded(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $failure = self::failure(DoctrineTransactionRunner::forEntityManager($manager, $this->registry), function () use ($manager): never {
            $this->connection->insert('ddrb_counter', ['id' => 7, 'counter_value' => 70]);
            self::assertInstanceOf(Counter::class, $manager->find(Counter::class, 7));

            throw new DomainException('refused');
        });

        self::assertInstanceOf(DomainException::class, $failure);
        self::assertNull($manager->find(Counter::class, 7), 'No ghost row.');
        self::assertTrue($manager->contains($host), 'The caller\'s entity stays managed.');
    }

    /**
     * QA-TD11-M1 [6b]: a value the run updated through DBAL and then loaded is not kept as the entity's state.
     */
    #[DataProvider('allServers')]
    public function testARefusalForgetsAValueTheRunUpdatedAndLoaded(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();

        $failure = self::failure(DoctrineTransactionRunner::forEntityManager($manager, $this->registry), function () use ($manager): never {
            $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 55 WHERE id = 1');
            $manager->find(Counter::class, 1);

            throw new DomainException('refused');
        });

        self::assertInstanceOf(DomainException::class, $failure);
        $counter = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $counter);
        self::assertSame(0, $counter->value, 'The rolled-back value is not served.');
    }

    /**
     * QA-TD11-M1: refresh() of a caller's entity inside the run re-hydrates it from the rolled-back transaction, so the
     * manager is cleared.
     */
    #[DataProvider('allServers')]
    public function testARefusalAfterRefreshingACallersEntityClears(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $host);

        $failure = self::failure(DoctrineTransactionRunner::forEntityManager($manager, $this->registry), function () use ($manager, $host): never {
            $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 66 WHERE id = 1');
            $manager->refresh($host);

            throw new DomainException('refused');
        });

        self::assertInstanceOf(DomainException::class, $failure);
        self::assertFalse($manager->contains($host), 'Cleared: the refreshed object holds rolled-back data.');
        $fresh = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $fresh);
        self::assertSame(0, $fresh->value);
    }

    /**
     * QA-TD11-M2 (documented): a nested refusal clears the manager, so the caller's pending changes are discarded too;
     * a caller that catches it and goes on commits none of them. The README and the contract say so.
     */
    #[DataProvider('allServers')]
    public function testANestedRefusalDiscardsTheCallersPendingChanges(string $server): void
    {
        $this->boot($server);
        $manager = $this->manager();
        $runner  = DoctrineTransactionRunner::forEntityManager($manager, $this->registry);

        // With DBAL 3's emulated nesting the inner rollback also marks the transaction rollback-only, so the caller's
        // commit fails; with savepoints it commits. Either way none of the caller's pending changes is written.
        $outer = $this->failure($runner, function () use ($runner, $manager): void {
            $manager->persist(new Counter(9));
            self::assertInstanceOf(DomainException::class, $this->failure($runner, static function (): never {
                throw new DomainException('inner refused');
            }));
        });

        self::assertTrue(!$outer instanceof Throwable || !$outer instanceof DomainException);
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 9'));
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
}
