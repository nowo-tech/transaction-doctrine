<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_diff_key;
use function array_intersect_key;
use function array_key_exists;
use function assert;
use function count;
use function is_object;
use function ksort;
use function spl_object_id;

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
 *   - domain failure (any other exception) with the manager open: cleared when the operation flushed inside the
 *     runner's transaction, re-hydrated an entity that was already managed (refresh(), a proxy initialised), or left
 *     changes pending; otherwise every entity that entered the identity map during the run (loaded, referenced) is
 *     detached, since it may hold data the rollback undid, and a caller's other entities stay managed after a plain
 *     refusal. If that detach cascaded into a caller's entity, the manager is cleared instead. Inside a nested run, "pending" includes
 *     the caller's own pending changes: a nested refusal clears them too.
 *   Clearing or resetting a shared manager also discards the caller's own pending changes, as
 *   EntityManager::wrapInTransaction() does.
 *   That is the contract of forEntityManager(), for managers the bundle owns. With the host application's manager
 *   use forHostEntityManager(): it never closes, clears or resets an open manager (REQ-RUNTIME-003 rule 2), replaces
 *   a closed one through the registry, and only forgets what the failed run brought in.
 *
 * Transient failures (deadlock, lock wait timeout, serialization failure, lost savepoint) are rethrown as
 * TransientStorageFailure with the storage error as previous; anything else is rethrown unchanged.
 *
 * Stateless: safe as a shared service in a long-running worker.
 *
 * An optional PSR-3 logger (default NullLogger, passed as the last factory argument and forwarded to the
 * ConnectionRepairer) records, with structured context only (exception class and code, levels, action; never SQL,
 * parameters or exception messages): the failure and whether it was classified transient (debug / notice), the repair
 * (notice / warning) and the entity manager reset or clear (debug / notice).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DoctrineTransactionRunner implements TransactionRunnerInterface
{
    public const HOST_PENDING_MESSAGE = 'The host entity manager has pending changes (scheduled insertions, updates or deletions): flush or clear them before running a unit of work with forHostEntityManager(); a failed run would otherwise discard them.';

    private TransientFailureClassifier $classifier;

    private ConnectionRepairer $repairer;

    private function __construct(
        private ?Connection $connection,
        private ?EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry,
        private ?string $managerName,
        private ?int $retryAfterSeconds,
        private LoggerInterface $logger,
        private bool $hostManager = false,
    ) {
        $this->classifier = new TransientFailureClassifier();
        $this->repairer   = new ConnectionRepairer(null, $logger);
    }

    /**
     * Runs DBAL-only units of work on one connection.
     */
    public static function forConnection(Connection $connection, ?int $retryAfterSeconds = 1, ?LoggerInterface $logger = null): self
    {
        return new self($connection, null, null, null, $retryAfterSeconds, $logger ?? new NullLogger());
    }

    /**
     * Runs ORM units of work on a manager the bundle owns: flushes the manager before the commit. After a failure the
     * manager is closed and reset through the registry (fetched from it on every call), or cleared when no registry is
     * given (see the class description).
     *
     * Only for managers the bundle owns (its own manager name / connection). After ANY storage failure (a DBAL or
     * driver error in the chain: a unique violation, a deadlock, a lost connection) it closes and resets the manager
     * through the registry even when the ORM left it open, and it clears an open manager after some domain failures.
     * Never pass the host application's manager (the default one, shared with the application): use
     * forHostEntityManager(), which never closes, clears or resets an open manager (REQ-RUNTIME-003 rule 2) and
     * replaces it only when it is closed.
     */
    public static function forEntityManager(
        EntityManagerInterface $entityManager,
        ?ManagerRegistry $registry = null,
        ?string $managerName = null,
        ?int $retryAfterSeconds = 1,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(null, $entityManager, $registry, $managerName, $retryAfterSeconds, $logger ?? new NullLogger());
    }

    /**
     * Runs ORM units of work on the host application's manager (REQ-RUNTIME-003 rule 2): flushes it before the commit
     * like forEntityManager(), but after a failure it never closes, clears or resets an open manager.
     *
     * The manager must be clean when run() starts (audit TA3-6): with pending changes (scheduled insertions, updates
     * or deletions, after UnitOfWork::computeChangeSets(), which scans the identity map and cascade-persists new
     * entities it reaches) run() throws LogicException before opening a transaction, since a flush inside the run
     * would carry the host's work into a transaction that may roll back. Flush or clear first.
     *
     * After a failure it only:
     *
     * - replaces a closed manager through the registry (resetManager(); without a registry a closed manager is left
     *   for the host, or for WorkerConnectionHygiene at the next request);
     * - forgets what the failed run brought into an open manager (entities that entered the identity map or were
     *   scheduled for insertion during the run): they are detached without cascading into the caller's entities,
     *   removed from the caller's initialized collections, and a to-one of a caller's entity pointing at one gets its
     *   original value back. A later flush by the host therefore cannot write the rolled-back entities again, and the
     *   identity map does not serve their rows.
     *
     * That is all it covers. Any other change the operation made to the caller's entities (a field, a removed
     * element, a value flushed and rolled back) stays, and a notice is logged when they have pending changes: the
     * caller decides whether to refresh() or clear().
     */
    public static function forHostEntityManager(
        EntityManagerInterface $entityManager,
        ?ManagerRegistry $registry = null,
        ?string $managerName = null,
        ?int $retryAfterSeconds = 1,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(null, $entityManager, $registry, $managerName, $retryAfterSeconds, $logger ?? new NullLogger(), true);
    }

    /**
     * With forHostEntityManager(), throws LogicException (HOST_PENDING_MESSAGE) when the host's manager has pending
     * changes before the run; when UnitOfWork::computeChangeSets() itself threw, that exception is its previous.
     */
    public function run(callable $operation): mixed
    {
        $manager = $this->manager();
        // Audit TA3-6: a flush inside the run (or the runner's own) would carry the host's pending work into a
        // transaction that may roll back, and forgetting the run's entities would then silently lose it.
        // A unit of work that cannot compute its change sets counts as pending; its exception is kept as previous.
        $cause = null;
        if ($this->hostManager && $manager instanceof EntityManagerInterface && $this->hasPendingChanges($manager, $cause)) {
            throw new LogicException(self::HOST_PENDING_MESSAGE, 0, $cause);
        }

        $connection = $manager?->getConnection() ?? $this->connection;
        assert($connection instanceof Connection);
        // (int): DBAL 3 declares no native return type, so a DBAL 3 test double returns null (1.7.2).
        $entryLevel = (int) $connection->getTransactionNestingLevel();
        $observer   = new RunObserver();
        // Entities managed before the run: one the run re-hydrates (refresh(), a proxy initialised) belongs to the caller.
        $before = $manager instanceof EntityManagerInterface ? $this->managedEntities($manager) : [];
        $manager?->getEventManager()->addEventListener([Events::postFlush, Events::postLoad], $observer);

        try {
            $connection->beginTransaction();
            if ($entryLevel === 0) {
                $this->clearStaleRollbackOnly($connection);
            }

            $result = $operation();
            $manager?->flush();
            $connection->commit();

            return $result;
        } catch (Throwable $throwable) {
            $alreadyTransient = $throwable instanceof TransientStorageFailure;
            $transient        = $alreadyTransient || $this->classifier->isTransient($throwable);
            SafeLog::log($this->logger, $transient ? 'notice' : 'debug', 'Transactional operation failed.', [
                'transient'   => $transient,
                'entry_level' => $entryLevel,
                'has_manager' => $manager instanceof EntityManagerInterface,
            ] + SafeLog::throwable($throwable));

            $this->repairer->afterFailure($connection, $entryLevel);

            if ($manager instanceof EntityManagerInterface) {
                $this->discardPendingChanges($manager, $throwable, $observer, $before);
            }

            if ($alreadyTransient || !$transient) {
                throw $throwable;
            }

            throw TransientStorageFailure::fromPrevious($throwable, $this->retryAfterSeconds);
        } finally {
            $manager?->getEventManager()->removeEventListener([Events::postFlush, Events::postLoad], $observer);
        }
    }

    /**
     * A transaction this runner just began at the outermost level cannot be rollback-only yet: a set flag was left by
     * an earlier transaction on a connection that was closed (Connection::close() keeps the flag on DBAL 3 and 4), and
     * it would make this run's commit fail. One ROLLBACK of the fresh transaction clears it; the run then begins again.
     * Only at the outermost level: with auto-commit disabled the run enters at level 1 (DBAL's own transaction), where a
     * set flag may be the caller's, so it is left to ConnectionRepairer, which clears it when it closes (audit VQ-6).
     */
    private function clearStaleRollbackOnly(Connection $connection): void
    {
        if (!$connection->isRollbackOnly()) {
            return;
        }

        SafeLog::log($this->logger, 'notice', 'Cleared a rollback-only flag left on a closed connection.', []);
        $connection->rollBack();
        $connection->beginTransaction();
    }

    /**
     * @param array<int, object> $before The entities managed when the run started, by spl_object_id
     */
    private function discardPendingChanges(EntityManagerInterface $manager, Throwable $failure, RunObserver $observer, array $before): void
    {
        if ($this->hostManager) {
            $this->releaseHostManager($manager, $before);

            return;
        }

        if (!$manager->isOpen() || $this->isStorageFailure($failure)) {
            if ($this->registry instanceof ManagerRegistry) {
                SafeLog::log($this->logger, 'notice', 'Closing and resetting the entity manager after a storage failure.', [
                    'manager'  => $this->managerName,
                    'was_open' => $manager->isOpen(),
                ]);
                $manager->close();
                $this->registry->resetManager($this->managerName);
            } elseif ($manager->isOpen()) {
                SafeLog::log($this->logger, 'notice', 'Clearing the entity manager after a storage failure (no registry to reset it).', [
                    'manager' => $this->managerName,
                ]);
                $manager->clear();
            }

            return;
        }

        // Domain failure, manager open. The run's transaction was rolled back, so the identity map must not keep
        // anything it saw: a flush inside the run, or a caller's entity re-hydrated inside it, makes the manager stale.
        if ($observer->flushed || array_intersect_key($observer->loaded, $before) !== []) {
            SafeLog::log($this->logger, 'debug', 'Clearing the entity manager: the run flushed or re-hydrated a managed entity.', [
                'flushed' => $observer->flushed,
            ]);
            $manager->clear();

            return;
        }

        // Everything that entered the identity map during the run may hold rolled-back data (a row written through
        // DBAL, then found or referenced with getReference(), which fires no postLoad): forget it.
        foreach (array_diff_key($this->managedEntities($manager), $before) as $entity) {
            if ($manager->contains($entity)) {
                $manager->detach($entity);
            }
        }

        // A detach cascades along cascade-detach associations: if it reached a caller's entity, a partial detach would
        // silently drop that entity alone (its later edits lost), so clear instead. The same when changes are pending.
        foreach ($before as $entity) {
            if (!$manager->contains($entity)) {
                SafeLog::log($this->logger, 'debug', 'Clearing the entity manager: a detach reached a caller entity.', []);
                $manager->clear();

                return;
            }
        }

        if ($this->hasPendingChanges($manager)) {
            SafeLog::log($this->logger, 'debug', 'Clearing the entity manager: changes were left pending.', []);
            $manager->clear();
        }
    }

    /**
     * The host's manager after a failed run: a closed one is replaced, an open one is never closed, cleared or reset.
     * Only what the run brought in is forgotten: it is detached without cascading into the caller's entities (audit
     * TA3-1), and unlinked from them (TA3-2): removed from their initialized collections and replaced, on a to-one
     * association, by the association's original value. Anything else the operation changed on the caller's entities
     * stays; a notice says so.
     *
     * @param array<int, object> $before The entities managed or scheduled for insertion when the run started
     */
    private function releaseHostManager(EntityManagerInterface $manager, array $before): void
    {
        if (!$manager->isOpen()) {
            if ($this->registry instanceof ManagerRegistry) {
                SafeLog::log($this->logger, 'notice', 'Replacing the closed host entity manager.', [
                    'manager' => $this->managerName,
                ]);
                $this->registry->resetManager($this->managerName);
            }

            return;
        }

        $unitOfWork = $manager->getUnitOfWork();
        $brought    = array_diff_key($this->managedEntities($manager) + $unitOfWork->getScheduledEntityInsertions(), $before);
        // UnitOfWork::detach() follows cascade: detach into any entity; doDetach() with the caller's entities already
        // "visited" stops there. Same private signature in ORM 2.20 and 3.x.
        $detach = Closure::bind(static function (UnitOfWork $unitOfWork, object $entity, array $visited): void {
            $unitOfWork->doDetach($entity, $visited);
        }, null, UnitOfWork::class);
        foreach ($brought as $entity) {
            $detach($unitOfWork, $entity, $before);
        }

        $changed = 0;
        foreach ($before as $entity) {
            if ($manager->contains($entity) && !$unitOfWork->isUninitializedObject($entity)
                && $this->unlinkBrought($manager, $entity, $brought)) {
                ++$changed;
            }
        }

        SafeLog::log($this->logger, 'debug', 'Detached what the failed run brought into the host entity manager.', [
            'manager'  => $this->managerName,
            'detached' => count($brought),
        ]);

        if ($changed > 0) {
            SafeLog::log($this->logger, 'notice', 'Entities the host managed before the failed run still have pending changes (the host\'s own or the run\'s); the host decides whether to refresh() or clear().', [
                'manager'  => $this->managerName,
                'entities' => $changed,
            ]);
        }
    }

    /**
     * Removes the brought entities from one caller entity's associations and tells whether it still differs from its
     * original data (a field, a to-one, a dirty collection). Reads only loaded state: no query, no change-set
     * computation (which would cascade-persist).
     *
     * @param array<int, object> $brought
     */
    private function unlinkBrought(EntityManagerInterface $manager, object $entity, array $brought): bool
    {
        $metadata = $manager->getClassMetadata($entity::class);
        $original = $manager->getUnitOfWork()->getOriginalEntityData($entity);
        $changed  = false;

        foreach ($metadata->getAssociationNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);

            if ($value instanceof PersistentCollection) {
                $changed = $this->unlinkFromCollection($value, $brought) || $changed;

                continue;
            }

            // The run started clean (TA3-6), so a managed caller entity has original data; null otherwise (TA3-7).
            if (is_object($value) && isset($brought[spl_object_id($value)])) {
                $metadata->setFieldValue($entity, $field, $value = $original[$field] ?? null);
            }

            $changed = $changed || (array_key_exists($field, $original) && $value !== $original[$field]);
        }

        foreach ($metadata->getFieldNames() as $field) {
            $changed = $changed || (array_key_exists($field, $original) && $metadata->getFieldValue($entity, $field) !== $original[$field]);
        }

        return $changed;
    }

    /**
     * @param PersistentCollection<array-key, mixed> $collection
     * @param array<int, object> $brought
     *
     * @return bool Whether the collection still has changes to flush
     */
    private function unlinkFromCollection(PersistentCollection $collection, array $brought): bool
    {
        // unwrap(): no orphan-removal scheduling of a detached element, and no load of an uninitialized collection
        // (add() on one keeps the new element in the wrapped collection without initializing it).
        $elements = $collection->unwrap();
        foreach ($elements->toArray() as $key => $element) {
            if (is_object($element) && isset($brought[spl_object_id($element)])) {
                $elements->remove($key);
            }
        }

        $snapshot = [];
        foreach ($collection->getSnapshot() as $element) {
            if (is_object($element) && !isset($brought[spl_object_id($element)])) {
                $snapshot[spl_object_id($element)] = true;
            }
        }

        $current = [];
        foreach ($elements->toArray() as $element) {
            if (is_object($element)) {
                $current[spl_object_id($element)] = true;
            }
        }

        // Only the run's elements made it differ from what was loaded: back to clean.
        ksort($current);
        ksort($snapshot);
        if ($current === $snapshot) {
            $collection->isInitialized() ? $collection->takeSnapshot() : $collection->setDirty(false);

            return false;
        }

        return $collection->isDirty();
    }

    /**
     * @return array<int, object> The managed entities (proxies included), by spl_object_id
     */
    private function managedEntities(EntityManagerInterface $manager): array
    {
        $entities = [];

        foreach ($manager->getUnitOfWork()->getIdentityMap() as $byId) {
            foreach ($byId as $entity) {
                $entities[spl_object_id($entity)] = $entity;
            }
        }

        return $entities;
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

    /**
     * The cheap signals first (scheduled insertions, deletions and collection changes); only then
     * UnitOfWork::computeChangeSets(), which scans the whole identity map and, through cascade-persist associations,
     * persists new entities it reaches (their prePersist callbacks run, generated ids may be assigned). A unit of work
     * that cannot even compute its change sets counts as pending, and what computeChangeSets() threw is handed back in
     * $cause (kept as the previous exception of the host runner's LogicException, audit TA-3 Info 1).
     *
     * @param-out Throwable|null $cause
     */
    private function hasPendingChanges(EntityManagerInterface $manager, ?Throwable &$cause = null): bool
    {
        $cause      = null;
        $unitOfWork = $manager->getUnitOfWork();

        if ($this->hasScheduledWork($unitOfWork)) {
            return true;
        }

        try {
            $unitOfWork->computeChangeSets();
        } catch (Throwable $throwable) {
            $cause = $throwable;

            return true;
        }

        return $this->hasScheduledWork($unitOfWork);
    }

    private function hasScheduledWork(UnitOfWork $unitOfWork): bool
    {
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
