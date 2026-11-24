<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Nowo\TransactionContract\TransientFailureKind;
use Throwable;

/**
 * Dispatched (PSR-14, by class name) by a write unit right before it replays its work: after the failed attempt's
 * entity managers were cleared or reset, after every ResetBetweenAttemptsInterface service was called, before the
 * next attempt. Never before the first attempt, never after the last failure.
 *
 * For listeners that observe replays (metrics, tracing) or reset state they cannot tag. A listener that throws aborts
 * the replay exactly as a failing resetter does (ConcurrencyConflict with the listener's exception as
 * ConcurrencyConflict::$resetFailure).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class WriteUnitRetryEvent
{
    /**
     * @param int $attempt The attempt about to run (2 or more)
     * @param Throwable $failure The previous attempt's failure (the lock conflict)
     * @param TransientFailureKind $kind Kind of that conflict
     * @param string|null $unit The unit's name: the route name of a controller, the message class of a consumed
     *                          message, what a programmatic caller passed to WriteUnit::run(), or null
     */
    public function __construct(
        public int $attempt,
        public Throwable $failure,
        public TransientFailureKind $kind,
        public ?string $unit = null,
    ) {
    }
}
