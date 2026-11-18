<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Generator;
use LogicException;
use Nowo\TransactionDoctrine\SafeLog;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Random\Randomizer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

use function iterator_to_array;
use function usleep;

/**
 * Runs one write unit and replays it after a lock conflict: a deadlock (MySQL / MariaDB 1213, PostgreSQL 40P01) or a
 * lock wait timeout (1205, 55P03) by default, found in the failure's previous chain (the contract's
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
 * A replay clears the open entity managers: every entity the caller loaded before run() is detached afterwards.
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
    ) {
        $this->logger     = $logger ?? new NullLogger();
        $this->classifier = new TransientFailureClassifier();
        $this->repairer   = $repairer ?? new DoctrineStateRepairer($registry, $this->logger);
        $this->random     = $random ?? new Randomizer();
        // A Generator cannot be traversed twice; a unit with several replays reads the resetters on each one.
        $this->resetters = $resetters instanceof Generator ? iterator_to_array($resetters, false) : $resetters;
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

            $this->repairer->discardUnitOfWork();

            if ($this->ledger instanceof CommitLedger && $this->ledger->count() !== $writes) {
                SafeLog::log($this->logger, 'warning', 'Write unit not replayed: the failed attempt had already committed.', [
                    'attempts' => $attempt,
                    'kind'     => $kind->value,
                ] + SafeLog::throwable($failure));

                // No Retry-After hint: part of the unit committed, so a blind retry could repeat it.
                throw new ConcurrencyConflict($attempt, $kind, null, $failure, true);
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
            $this->prepareReplay($attempt + 1, $failure, $kind, $unit);
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
     */
    public function conflictKind(Throwable $failure): ?TransientFailureKind
    {
        if (self::containsConcurrencyConflict($failure)) {
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
        foreach ($wrapper->getWrappedExceptions(null, true) as $wrapped) {
            $one = $this->classifier->classify($wrapped);
            if (!$one instanceof TransientFailureKind || !$this->policy->replays($one)) {
                return null;
            }

            $kind ??= $one;
        }

        return $kind;
    }

    private static function containsConcurrencyConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConcurrencyConflict) {
                return true;
            }

            if ($current instanceof WrappedExceptionsInterface) {
                foreach ($current->getWrappedExceptions(null, true) as $wrapped) {
                    if (self::containsConcurrencyConflict($wrapped)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Empties request memos before the given attempt (resetters in order, then WriteUnitRetryEvent). A failure of
     * either abandons the replay (whatever it left open discarded first, audit TA-6): the unit gives up with the
     * attempt's conflict, the reset failure attached. So does a resetter or listener that left a transaction open or
     * entity changes pending: the replay would run nested (its flush never committing) or write them; they are
     * discarded first.
     */
    private function prepareReplay(int $next, Throwable $failure, TransientFailureKind $kind, ?string $unit): void
    {
        try {
            foreach ($this->resetters as $resetter) {
                $resetter->resetBetweenAttempts();
            }

            $this->dispatcher?->dispatch(new WriteUnitRetryEvent($next, $failure, $kind, $unit));
        } catch (Throwable $resetFailure) {
            // Audit TA-6: a resetter or listener may have opened a transaction (or persisted) before throwing.
            $this->repairer->discardUnitOfWork();
            $this->abandonReplay($next - 1, $failure, $kind, $resetFailure);
        }

        if ($this->insideTransaction() || $this->hasPendingChanges()) {
            $this->repairer->discardUnitOfWork();
            $this->abandonReplay($next - 1, $failure, $kind, new LogicException('A reset-between-attempts service or retry listener left a transaction or pending changes.'));
        }
    }

    private function abandonReplay(int $attempts, Throwable $failure, TransientFailureKind $kind, Throwable $resetFailure): never
    {
        SafeLog::log($this->logger, 'error', 'Write unit not replayed: resetting request state between attempts failed.', [
            'attempts' => $attempts,
            'kind'     => $kind->value,
        ] + SafeLog::throwable($resetFailure));

        throw new ConcurrencyConflict($attempts, $kind, $this->policy->retryAfterSeconds, $failure, false, $resetFailure);
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
     */
    private function hasPendingChanges(): bool
    {
        foreach ($this->registry->getManagers() as $manager) {
            if (!$manager instanceof EntityManagerInterface || !$manager->isOpen()) {
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

    private function hasScheduledWork(UnitOfWork $unitOfWork): bool
    {
        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== [];
    }
}
