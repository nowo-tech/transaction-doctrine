<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
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
 * - the entity manager is closed and reset through the ManagerRegistry when one is given, and cleared otherwise, so
 *   nothing the failed operation persisted or changed is committed by a later flush, run() or request. Inside a
 *   caller's transaction that also discards the caller's pending changes, as EntityManager::wrapInTransaction() does.
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

        try {
            $connection->beginTransaction();
            $result = $operation();
            $manager?->flush();
            $connection->commit();

            return $result;
        } catch (Throwable $throwable) {
            $this->repairer->afterFailure($connection, $entryLevel);

            if ($manager instanceof EntityManagerInterface) {
                $this->discardPendingChanges($manager);
            }

            if ($throwable instanceof TransientStorageFailure || !$this->classifier->isTransient($throwable)) {
                throw $throwable;
            }

            throw TransientStorageFailure::fromPrevious($throwable, $this->retryAfterSeconds);
        }
    }

    private function discardPendingChanges(EntityManagerInterface $manager): void
    {
        if ($this->registry instanceof ManagerRegistry) {
            $manager->close();
            $this->registry->resetManager($this->managerName);

            return;
        }

        if ($manager->isOpen()) {
            $manager->clear();
        }
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
