<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use PDOException;
use Throwable;

use function assert;

/**
 * Safe default TransactionRunnerInterface for plain Doctrine, for a bundle to use when the host wires no adapter.
 *
 * One attempt, no retry (a host that wants retries aliases the port to a retrying adapter, such as
 * nowo-tech/doctrine-deadlock-retry-bundle's). The runner opens a transaction, runs the operation, flushes the entity
 * manager when it has one, and commits. After any failure it leaves neither a poisoned connection nor pending changes:
 *
 * - the levels the runner opened are rolled back to the entry level; the connection is closed only when that
 *   rollback fails (after a MySQL / MariaDB deadlock in a nested transaction the server dropped the savepoints and
 *   ROLLBACK TO SAVEPOINT fails with 1305). An ordinary failure therefore keeps session state: variables, GET_LOCK()
 *   and PostgreSQL advisory locks survive;
 * - nothing the failed operation persisted or changed stays pending in the entity manager, while a host's own entities
 *   are disturbed only as much as that requires:
 *   - storage failure (a DBAL / driver error in the chain) or a manager the ORM closed: the manager is closed and
 *     reset through the ManagerRegistry when one is given, and cleared otherwise (its state cannot be trusted);
 *   - domain failure (any other exception) with the manager open: cleared when the operation left changes pending or
 *     flushed inside the runner's transaction (its writes were rolled back, so the identity map is stale), and left
 *     untouched otherwise, so a host's entities stay managed after a plain refusal.
 *   Clearing or resetting a shared manager also discards the caller's own pending changes, as
 *   EntityManager::wrapInTransaction() does.
 *
 * Transient failures (deadlock, lock wait timeout, serialization failure, lost savepoint) are rethrown as
 * TransientStorageFailure with the storage error as previous; anything else is rethrown unchanged.
 *
 * Stateless: safe as a shared service in a long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DoctrineTransactionRunner implements TransactionRunnerInterface
{
    private TransientFailureClassifier $classifier;

    private ConnectionRepairer $repairer;

    private function __construct(
        private ?Connection $connection,
        private ?EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry,
        private ?string $managerName,
        private ?int $retryAfterSeconds,
    ) {
        $this->classifier = new TransientFailureClassifier();
        $this->repairer   = new ConnectionRepairer();
    }

    /**
     * Runs DBAL-only units of work on one connection.
     */
    public static function forConnection(Connection $connection, ?int $retryAfterSeconds = 1): self
    {
        return new self($connection, null, null, null, $retryAfterSeconds);
    }

    /**
     * Runs ORM units of work: flushes the manager before the commit. After a failure the manager is closed and reset
     * through the registry (fetched from it on every call), or cleared when no registry is given.
     */
    public static function forEntityManager(
        EntityManagerInterface $entityManager,
        ?ManagerRegistry $registry = null,
        ?string $managerName = null,
        ?int $retryAfterSeconds = 1,
    ): self {
        return new self(null, $entityManager, $registry, $managerName, $retryAfterSeconds);
    }

    public function run(callable $operation): mixed
    {
        $manager    = $this->manager();
        $connection = $manager?->getConnection() ?? $this->connection;
        assert($connection instanceof Connection);
        $entryLevel = $connection->getTransactionNestingLevel();
        $flushes    = new FlushObserver();
        $manager?->getEventManager()->addEventListener([Events::postFlush], $flushes);

        try {
            $connection->beginTransaction();
            $result = $operation();
            $manager?->flush();
            $connection->commit();

            return $result;
        } catch (Throwable $throwable) {
            $this->repairer->afterFailure($connection, $entryLevel);

            if ($manager instanceof EntityManagerInterface) {
                $this->discardPendingChanges($manager, $throwable, $flushes->flushed);
            }

            if ($throwable instanceof TransientStorageFailure || !$this->classifier->isTransient($throwable)) {
                throw $throwable;
            }

            throw TransientStorageFailure::fromPrevious($throwable, $this->retryAfterSeconds);
        } finally {
            $manager?->getEventManager()->removeEventListener([Events::postFlush], $flushes);
        }
    }

    private function discardPendingChanges(EntityManagerInterface $manager, Throwable $failure, bool $flushed): void
    {
        if (!$manager->isOpen() || $this->isStorageFailure($failure)) {
            if ($this->registry instanceof ManagerRegistry) {
                $manager->close();
                $this->registry->resetManager($this->managerName);
            } elseif ($manager->isOpen()) {
                $manager->clear();
            }

            return;
        }

        if ($flushed || $this->hasPendingChanges($manager)) {
            $manager->clear();
        }
    }

    private function isStorageFailure(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof DbalException || $current instanceof DriverExceptionInterface || $current instanceof PDOException) {
                return true;
            }
        }

        return false;
    }

    private function hasPendingChanges(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();

        try {
            $unitOfWork->computeChangeSets();
        } catch (Throwable) {
            return true;   // an inconsistent unit of work cannot be trusted either
        }

        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== [];
    }

    private function manager(): ?EntityManagerInterface
    {
        if (!$this->registry instanceof ManagerRegistry) {
            return $this->entityManager;
        }

        $manager = $this->registry->getManager($this->managerName);

        return $manager instanceof EntityManagerInterface ? $manager : $this->entityManager;
    }
}
