<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\SafeLog;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Random\Randomizer;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;

use function usleep;

/**
 * Runs one write unit and replays it after a lock conflict: a deadlock (MySQL / MariaDB 1213, PostgreSQL 40P01) or a
 * lock wait timeout (1205, 55P03) by default, found anywhere in the failure's previous chain (the contract's
 * TransientStorageFailure of a bundle's runner included) or inside a Messenger HandlerFailedException. At most
 * WriteUnitPolicy::$maxAttempts attempts with a full-jitter pause between them; between two attempts the levels the unit
 * left open are rolled back, closed entity managers reset and open ones cleared (DoctrineStateRepairer), so an attempt
 * never sees what the failed one loaded. The last conflict is rethrown as ConcurrencyConflict; any other failure is
 * rethrown unchanged at once (and so is a ConcurrencyConflict of an inner unit: it already replayed).
 *
 * Outermost unit only: when any connection of the registry is inside a transaction (the caller's, or an outer unit's),
 * the work runs once and a failure propagates unchanged, since the database may have rolled back the caller's whole
 * transaction. A connection with auto-commit disabled is always inside one, so a write unit never replays there.
 *
 * The transient-failure classification is TransientFailureClassifier's. Stateless (FrankenPHP worker, kernel reset
 * off): nothing of a run is kept on the service. Logs constant messages with structured context only.
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

    /**
     * @param ClockInterface|null $clock Pauses through the clock when given (a MockClock in tests), else usleep()
     */
    public function __construct(
        private ManagerRegistry $registry,
        private WriteUnitPolicy $policy = new WriteUnitPolicy(),
        ?DoctrineStateRepairer $repairer = null,
        private ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null,
        ?Randomizer $random = null,
    ) {
        $this->logger     = $logger ?? new NullLogger();
        $this->classifier = new TransientFailureClassifier();
        $this->repairer   = $repairer ?? new DoctrineStateRepairer($registry, $this->logger);
        $this->random     = $random ?? new Randomizer();
    }

    public function run(callable $work): mixed
    {
        if ($this->insideTransaction()) {
            return $work(1, null);
        }

        $failure = null;

        for ($attempt = 1;; ++$attempt) {
            try {
                return $work($attempt, $failure);
            } catch (Throwable $caught) {
                $kind = $caught instanceof ConcurrencyConflict ? null : $this->conflictKind($caught);
                if (!$kind instanceof TransientFailureKind || !$this->policy->replays($kind)) {
                    throw $caught;
                }

                $failure = $caught;
            }

            $this->repairer->discardUnitOfWork();

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
     * The replayable kind of a failure: its previous chain, then the exceptions a Messenger HandlerFailedException
     * (or any WrappedExceptionsInterface in the chain) wraps; null when none is transient.
     */
    public function conflictKind(Throwable $failure): ?TransientFailureKind
    {
        $kind = $this->classifier->classify($failure);
        if ($kind instanceof TransientFailureKind) {
            return $kind;
        }

        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if (!$current instanceof WrappedExceptionsInterface) {
                continue;
            }

            foreach ($current->getWrappedExceptions(null, true) as $wrapped) {
                $kind = $this->classifier->classify($wrapped);
                if ($kind instanceof TransientFailureKind) {
                    return $kind;
                }
            }
        }

        return null;
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
}
