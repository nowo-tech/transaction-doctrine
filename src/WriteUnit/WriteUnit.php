<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Generator;
use InvalidArgumentException;
use LogicException;
use Nowo\TransactionContract\ConcurrencyConflict;
use Nowo\TransactionContract\TransientFailureKind;
use Nowo\TransactionDoctrine\OrphanRemovals;
use Nowo\TransactionDoctrine\SafeLog;
use Nowo\TransactionDoctrine\ThrowableWalk;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Random\Randomizer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

use function array_key_exists;
use function array_values;
use function count;
use function in_array;
use function iterator_to_array;
use function sprintf;
use function usleep;

/**
 * Runs one write unit and replays it after a lock conflict: a deadlock (MySQL / MariaDB 1213, PostgreSQL 40P01), a
 * lock wait timeout (1205, 55P03) or a serialization failure (PostgreSQL 40001, MariaDB 1020) by default, found in the failure's previous chain (the contract's
 * TransientStorageFailure of a bundle's runner included). At most WriteUnitPolicy::$maxAttempts attempts with a
 * full-jitter pause between them; between two attempts the levels the unit left open are rolled back, closed entity
 * managers reset and open ones cleared (DoctrineStateRepairer), so an attempt never sees what the failed one loaded.
 * The last conflict is rethrown as ConcurrencyConflict; any other failure is rethrown unchanged at once.
 *
 * **A unit must commit at most once, or be idempotent as a whole.** A replay runs the whole unit again: what an
 * earlier commit of the failed attempt wrote, and the side effects that followed it, would happen twice. With a
 * CommitLedger fed by Dbal\CommitTrackingMiddleware (the bundle's `commit_detection`, on by default), a failed attempt
 * that already committed (a COMMIT, or a write statement outside a transaction) is never replayed: the conflict is
 * rethrown at once as ConcurrencyConflict with `committed: true`. Without a ledger the rule is the caller's.
 *
 * The work runs once, never replayed, when:
 * - any connection of the registry is inside a transaction (the caller's, or an outer unit's): the database may have
 *   rolled back the caller's whole transaction (outermost unit only). A connection with auto-commit disabled is always
 *   inside one;
 * - any open entity manager has pending changes before the first attempt (a caller's unflushed edits or persists):
 *   clearing it for a replay would silently lose them.
 *
 * A replay never clears an open entity manager that already managed entities before run() (1.8, audit VD2-4,
 * REQ-RUNTIME-003 rule 2): what the failed attempt brought into it is detached, and the caller's entities that the
 * attempt changed or re-hydrated are refreshed from the database (KeptEntitiesRestorer), so they stay managed and the
 * next attempt starts from their committed state. When one cannot be restored (the attempt removed or detached it, or
 * its refresh failed), the unit is not replayed: ConcurrencyConflict (nothing committed: CONFLICT_001 with Retry-After),
 * the manager left open and not cleared. An open manager that managed nothing before run() is cleared, as before.
 * A unit scoped to named managers (forManagers(), or the `managers` argument; 1.7) repairs only those.
 * Request memos that keep entities must therefore be emptied before the replay: after the managers are cleared and
 * the pause, and right before the next attempt, every ResetBetweenAttemptsInterface given (in order) is called, then
 * a WriteUnitRetryEvent is dispatched when a PSR-14 dispatcher is given. Neither runs before the first attempt nor
 * after the last failure. When one of them throws, the replay is abandoned (the transaction levels and entity changes
 * it left are discarded first): the unit gives up with ConcurrencyConflict (the attempt's failure as previous, the
 * reset exception as ConcurrencyConflict::$resetFailure).
 *
 * Never replayed either: a ConcurrencyConflict anywhere in the chain or inside wrapped exceptions (an inner unit
 * already replayed), and a Messenger wrapper (HandlerFailedException, DelayedMessageHandlingException) unless every
 * wrapped exception is a replayable conflict and no handler already succeeded (HandledStamp).
 *
 * Use one retry layer: a write unit, or another retry (DoctrineDeadlockRetryBundle's retry service), not both nested.
 *
 * Stateless (FrankenPHP worker, kernel reset off): nothing of a run is kept on the service. Logs constant messages
 * with structured context only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class WriteUnit implements WriteUnitInterface
{
    private TransientFailureClassifier $classifier;

    private DoctrineStateRepairer $repairer;

    private LoggerInterface $logger;

    private Randomizer $random;

    /** @var iterable<ResetBetweenAttemptsInterface> */
    private iterable $resetters;

    /**
     * @param ClockInterface|null $clock Pauses through the clock when given (a MockClock in tests), else usleep()
     * @param CommitLedger|null $ledger Commit detection (Dbal\CommitTrackingMiddleware must feed it); null = none
     * @param iterable<ResetBetweenAttemptsInterface> $resetters Called in order before each replay (read on every
     *                                                           replay; a Generator is read once into a list)
     * @param EventDispatcherInterface|null $dispatcher Dispatches WriteUnitRetryEvent before each replay; null = none
     * @param list<string>|null $managers The entity managers a replay repairs (1.7, audit UD2-L-2): null (the
     *                                    default) repairs every manager of the registry, the host's default one
     *                                    included (1.8: one that managed entities before run() is restored, never
     *                                    cleared); a list of manager names repairs only those managers (a
     *                                    bundle's private manager), so the host's manager keeps its entities (see
     *                                    forManagers()). Every name must be a manager of the registry
     *
     * @throws InvalidArgumentException When $managers is empty or names a manager the registry does not have
     */
    public function __construct(
        private ManagerRegistry $registry,
        private WriteUnitPolicy $policy = new WriteUnitPolicy(),
        ?DoctrineStateRepairer $repairer = null,
        private ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        ?Randomizer $random = null,
        private ?CommitLedger $ledger = null,
        iterable $resetters = [],
        private ?EventDispatcherInterface $dispatcher = null,
        private ?array $managers = null,
    ) {
        if ($managers !== null) {
            $this->assertManagers($registry, $managers);
        }

        $this->logger     = $logger ?? new NullLogger();
        $this->classifier = new TransientFailureClassifier();
        $this->repairer   = $repairer ?? new DoctrineStateRepairer($registry, $this->logger);
        $this->random     = $random ?? new Randomizer();
        // A Generator cannot be traversed twice; a unit with several replays reads the resetters on each one.
        $this->resetters = $resetters instanceof Generator ? iterator_to_array($resetters, false) : $resetters;
    }

    /**
     * The same write unit, its replays scoped to the named entity managers (1.7, audit UD2-L-2): between two attempts
     * only these managers are reset or cleared; the host's default manager (and any other) keeps its entities.
     * Leftover transaction levels are still rolled back on every connection. Name every manager the unit writes
     * through: when one outside the scope is found closed after an attempt, or a replay is abandoned with pending
     * changes outside the scope, every manager is repaired (audits UI-1 / UI-3). The checks before the first attempt
     * still read every connection and manager (a caller's open transaction, or pending changes anywhere, make the unit
     * run once), and a replay that would start inside a transaction or over pending changes on any of them is
     * abandoned (ConcurrencyConflict), never run nested.
     *
     * @throws InvalidArgumentException When no name is given or one is not a manager of the registry
     */
    public function forManagers(string ...$managers): self
    {
        return new self(
            $this->registry,
            $this->policy,
            $this->repairer,
            $this->clock,
            $this->logger,
            $this->random,
            $this->ledger,
            $this->resetters,
            $this->dispatcher,
            array_values($managers),
        );
    }

    /**
     * @template T
     *
     * @param callable(int, ?Throwable): T $work The unit; called with the attempt number and the previous failure
     * @param string|null $unit A name for the unit, carried by WriteUnitRetryEvent (a route, a message class); the
     *                          bundle's controller listener and Messenger middleware pass one
     *
     * @return T
     */
    public function run(callable $work, ?string $unit = null): mixed
    {
        if ($this->insideTransaction()) {
            return $work(1, null);
        }

        if ($this->hasPendingChanges()) {
            SafeLog::log($this->logger, 'notice', 'Write unit runs once: an entity manager has pending changes.', []);

            return $work(1, null);
        }

        $kept = $this->keep();

        try {
            return $this->attempts($work, $unit, $kept);
        } finally {
            // $kept may hold the observers of re-attached managers (reattach()).
            foreach ($kept as $observer) {
                $observer->manager->getEventManager()->removeEventListener([Events::postLoad, Events::onFlush], $observer);
            }
        }
    }

    /**
     * @template T
     *
     * @param callable(int, ?Throwable): T $work
     * @param array<string, AttemptObserver> $kept Updated when a manager is re-attached (a new observer)
     *
     * @return T
     */
    private function attempts(callable $work, ?string $unit, array &$kept): mixed
    {
        $failure = null;

        for ($attempt = 1;; ++$attempt) {
            $writes = $this->ledger instanceof CommitLedger ? $this->ledger->count() : null;

            try {
                return $work($attempt, $failure);
            } catch (Throwable $caught) {
                $kind = $this->conflictKind($caught);
                if (!$kind instanceof TransientFailureKind) {
                    throw $caught;
                }

                $failure = $caught;
            }

            $unrestored = [...$this->repairer->discardUnitOfWork($this->managers, $this->kept($kept)), ...$this->reattach($kept)];
            foreach ($kept as $observer) {
                $observer->start();
            }

            if ($this->ledger instanceof CommitLedger && $this->ledger->count() !== $writes) {
                SafeLog::log($this->logger, 'warning', 'Write unit not replayed: the failed attempt had already committed.', [
                    'attempts' => $attempt,
                    'kind'     => $kind->value,
                ] + SafeLog::throwable($failure));

                // No Retry-After hint: part of the unit committed, so a blind retry could repeat it.
                throw new ConcurrencyConflict($attempt, $kind, null, $failure, true);
            }

            if ($unrestored !== []) {
                SafeLog::log($this->logger, 'warning', 'Write unit not replayed: entities managed before it could not be restored without clearing their manager.', [
                    'attempts' => $attempt,
                    'kind'     => $kind->value,
                    'managers' => count($unrestored),
                ] + SafeLog::throwable($failure));

                throw new ConcurrencyConflict($attempt, $kind, $this->policy->retryAfterSeconds, $failure);
            }

            if ($attempt >= $this->policy->maxAttempts) {
                SafeLog::log($this->logger, 'warning', 'Write unit gave up after repeated database lock conflicts.', [
                    'attempts' => $attempt,
                    'kind'     => $kind->value,
                ] + SafeLog::throwable($failure));

                throw new ConcurrencyConflict($attempt, $kind, $this->policy->retryAfterSeconds, $failure);
            }

            $pause = $this->pauseMilliseconds($attempt);
            SafeLog::log($this->logger, 'notice', 'Write unit met a database lock conflict; replaying it.', [
                'attempt'  => $attempt,
                'kind'     => $kind->value,
                'pause_ms' => $pause,
            ] + SafeLog::throwable($failure));
            $this->pause($pause);
            $this->prepareReplay($attempt + 1, $failure, $kind, $unit, $kept);
        }
    }

    /**
     * The pause after the given failed attempt, in milliseconds (full jitter between the policy's floor and ceiling).
     */
    public function pauseMilliseconds(int $attempt): int
    {
        [$floor, $ceiling] = $this->policy->pauseBounds($attempt);

        return $this->random->getInt($floor, $ceiling);
    }

    /**
     * The kind under which this unit replays a failure, or null when it must not be replayed: no replayable conflict
     * in it, a ConcurrencyConflict anywhere in it, or a Messenger wrapper with a non-conflict (or a succeeded handler)
     * among its wrapped exceptions.
     *
     * Messenger wrappers are read through the bounded ThrowableWalk, as TransientFailureClassifier::findConflict()
     * reads them (audit UI2-4): a cycle of wrappers ends, and what cannot be read whole (a getWrappedExceptions() that
     * throws or returns something that is not a Throwable, more than ThrowableWalk::MAX_NODES throwables) makes the
     * failure not replayable: it is rethrown unchanged, never replaced.
     */
    public function conflictKind(Throwable $failure): ?TransientFailureKind
    {
        if ($this->mayHoldConcurrencyConflict($failure)) {
            return null;
        }

        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof WrappedExceptionsInterface) {
                return $this->wrappedKind($current);
            }
        }

        $kind = $this->classifier->classify($failure);

        return $kind instanceof TransientFailureKind && $this->policy->replays($kind) ? $kind : null;
    }

    private function wrappedKind(WrappedExceptionsInterface $wrapper): ?TransientFailureKind
    {
        if ($wrapper instanceof HandlerFailedException && $wrapper->getEnvelope()->all(HandledStamp::class) !== []) {
            return null;
        }

        $kind = null;
        foreach (ThrowableWalk::leaves($wrapper) ?? [] as $wrapped) {
            $one = $this->classifier->classify($wrapped);
            if (!$one instanceof TransientFailureKind || !$this->policy->replays($one)) {
                return null;
            }

            $kind ??= $one;
        }

        return $kind;
    }

    /**
     * Whether a ConcurrencyConflict sits anywhere in the failure (getPrevious() and Messenger's wrapped exceptions),
     * or may sit in what the bounded walk could not read (truncated: an inner unit's conflict must never be replayed
     * again, so the unknown counts as one).
     */
    private function mayHoldConcurrencyConflict(Throwable $failure): bool
    {
        $walk = ThrowableWalk::of($failure);
        foreach ($walk as $current => $chainStart) {
            if ($current instanceof ConcurrencyConflict) {
                return true;
            }
        }

        return $walk->getReturn();
    }

    /**
     * Empties request memos before the given attempt (resetters in order, then WriteUnitRetryEvent). A failure of
     * either abandons the replay (whatever it left open discarded first, audit TA-6): the unit gives up with the
     * attempt's conflict, the reset failure attached. So does a resetter or listener that left a transaction open or
     * entity changes pending: the replay would run nested (its flush never committing) or write them; they are
     * discarded first.
     *
     * @param array<string, AttemptObserver> $kept
     */
    private function prepareReplay(int $next, Throwable $failure, TransientFailureKind $kind, ?string $unit, array $kept): void
    {
        try {
            foreach ($this->resetters as $resetter) {
                $resetter->resetBetweenAttempts();
            }

            $this->dispatcher?->dispatch(new WriteUnitRetryEvent($next, $failure, $kind, $unit));
        } catch (Throwable $resetFailure) {
            // Audit TA-6: a resetter or listener may have opened a transaction (or persisted) before throwing.
            $this->discardAbandoned($kept);
            $this->abandonReplay($next - 1, $failure, $kind, $resetFailure);
        }

        if ($this->insideTransaction() || $this->hasPendingChanges()) {
            $this->discardAbandoned($kept);
            $this->abandonReplay($next - 1, $failure, $kind, new LogicException('A reset-between-attempts service or retry listener left a transaction or pending changes.'));
        }
    }

    /**
     * Discards what an abandoned replay left: the scoped repair, or every manager when one outside the scope holds
     * pending changes (a resetter or listener persisted through the host's manager, audit UI-1). Run once only when
     * no manager had pending changes, so whatever is pending now was left by the unit or by those services. A manager
     * that managed entities before the unit is not cleared either (1.8, audit VD2-4).
     *
     * @param array<string, AttemptObserver> $kept
     */
    private function discardAbandoned(array $kept): void
    {
        // The pending-work check computed change sets (they move the original data): every kept entity is reloaded.
        $this->repairer->discardUnitOfWork($this->managers !== null && $this->hasPendingChanges($this->managers) ? null : $this->managers, $this->kept($kept), true);
        $this->reattach($kept, false);
    }

    /**
     * The open entity managers that already manage entities before the first attempt (the host's), each with an
     * observer of what every attempt does to it (1.8, audits VD2-4 / VQ): a replay restores them instead of clearing.
     *
     * @return array<string, AttemptObserver>
     */
    private function keep(): array
    {
        $kept = [];

        foreach ($this->registry->getManagers() as $name => $manager) {
            if (!$manager instanceof EntityManagerInterface || !$manager->isOpen()) {
                continue;
            }

            $entities = KeptEntitiesRestorer::managed($manager);
            if ($entities === []) {
                continue;
            }

            $observer = new AttemptObserver($manager, $entities);
            $manager->getEventManager()->addEventListener([Events::postLoad, Events::onFlush], $observer);
            $kept[$name] = $observer;
        }

        return $kept;
    }

    /**
     * Re-attaches the kept entities to every open manager that holds none of them any more (1.8.2, audits VQ5-1 /
     * VQ5-2): reset after the attempt's flush failed (by the repairer or host code, in place or by a new object), or
     * cleared by the attempt, or every kept entity detached. The host's captured objects are managed again and reloaded
     * (KeptEntitiesRestorer::reattach()); the observer follows the manager the registry serves now.
     *
     * @param array<string, AttemptObserver> $kept
     * @param bool $follow Replace the observer of a manager that changed (false: the run ends now, nothing to observe)
     *
     * @return list<string> The managers where a kept entity could not be reloaded (its row gone)
     */
    private function reattach(array &$kept, bool $follow = true): array
    {
        $failed = [];
        foreach ($kept as $name => $observer) {
            $manager = $this->registry->getManager($name);
            if (!$manager instanceof EntityManagerInterface || !$manager->isOpen()
                || KeptEntitiesRestorer::anyManaged($manager->getUnitOfWork(), $observer->kept)) {
                continue;
            }

            if (!KeptEntitiesRestorer::reattach($manager, $observer)) {
                $failed[] = $name;
            }

            if ($follow && ($manager !== $observer->manager || $manager->getUnitOfWork() !== $observer->unitOfWork)) {
                $observer->manager->getEventManager()->removeEventListener([Events::postLoad, Events::onFlush], $observer);
                $kept[$name] = new AttemptObserver($manager, $observer->kept, $observer->identifiers, $observer->versions);
                $manager->getEventManager()->addEventListener([Events::postLoad, Events::onFlush], $kept[$name]); // @igor-ignore — per-run observer, removed in run()'s finally
            }
        }

        return $failed;
    }

    /**
     * The observers of the managers the registry still serves with the same unit of work: a manager reset after it was
     * closed is a new one, nothing of the old one is kept. That includes a reset in place, which DoctrineBundle does on
     * its lazy entity manager service (the same object, a new entity manager and unit of work behind it): host code
     * (or a DoctrineTransactionRunner::forEntityManager() with a registry) that resets a manager a failed flush closed
     * leaves an empty manager whose old entities can never be restored (1.8.1).
     *
     * @param array<string, AttemptObserver> $kept
     *
     * @return array<string, AttemptObserver>
     */
    private function kept(array $kept): array
    {
        $current = [];

        foreach ($kept as $name => $observer) {
            if ($this->registry->getManager($name) === $observer->manager && $observer->manager->getUnitOfWork() === $observer->unitOfWork) {
                $current[$name] = $observer;
            }
        }

        return $current;
    }

    private function abandonReplay(int $attempts, Throwable $failure, TransientFailureKind $kind, Throwable $resetFailure): never
    {
        SafeLog::log($this->logger, 'error', 'Write unit not replayed: resetting request state between attempts failed.', [
            'attempts' => $attempts,
            'kind'     => $kind->value,
        ] + SafeLog::throwable($resetFailure));

        throw new ConcurrencyConflict($attempts, $kind, $this->policy->retryAfterSeconds, $failure, false, $resetFailure);
    }

    /**
     * @param list<string> $managers
     */
    private function assertManagers(ManagerRegistry $registry, array $managers): void
    {
        if ($managers === []) {
            throw new InvalidArgumentException('A write unit scoped to managers needs at least one manager name.');
        }

        $known = $registry->getManagerNames();
        foreach ($managers as $name) {
            if (!array_key_exists($name, $known)) {
                throw new InvalidArgumentException(sprintf('The write unit names the entity manager "%s", which the registry does not have.', $name));
            }
        }
    }

    private function pause(int $milliseconds): void
    {
        if ($this->clock instanceof ClockInterface) {
            $this->clock->sleep($milliseconds / 1000);

            return;
        }

        usleep($milliseconds * 1000);
    }

    private function insideTransaction(): bool
    {
        foreach ($this->registry->getConnections() as $connection) {
            if ($connection instanceof Connection && $connection->isTransactionActive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an open entity manager holds work the caller has not flushed (the cheap scheduled signals first, then
     * the change sets of the managed entities, as DoctrineTransactionRunner does). An unreadable unit of work counts.
     *
     * @param list<string> $except Managers not checked (by name)
     */
    private function hasPendingChanges(array $except = []): bool
    {
        foreach ($this->registry->getManagers() as $name => $manager) {
            if (in_array($name, $except, true) || !$manager instanceof EntityManagerInterface || !$manager->isOpen()) {
                continue;
            }

            $unitOfWork = $manager->getUnitOfWork();
            if ($this->hasScheduledWork($unitOfWork)) {
                return true;
            }

            try {
                $unitOfWork->computeChangeSets();
            } catch (Throwable) {
                return true;
            }

            if ($this->hasScheduledWork($unitOfWork)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Managed orphan removals count (1.9.2, audit XD-1): `clear()` on an inverse one-to-many with orphanRemoval, or an
     * inverse one-to-one with orphanRemoval set to null, schedules nothing else, and a replay's repair would drop it.
     */
    private function hasScheduledWork(UnitOfWork $unitOfWork): bool
    {
        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== []
            || OrphanRemovals::pending($unitOfWork) !== [];
    }
}
