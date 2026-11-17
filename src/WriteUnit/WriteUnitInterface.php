<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Throwable;

/**
 * Runs one host write unit (a controller, a consumed message, a command step) and replays it after a lock conflict.
 *
 * The work must be replayable: it loads what it changes itself, keeps side effects until after its commit (the same
 * contract as a Messenger retry), and **commits at most once, or is idempotent as a whole**. It receives the attempt
 * number (1-based) and the previous attempt's failure, so it can rebuild its inputs on a replay.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface WriteUnitInterface
{
    /**
     * @template T
     *
     * @param callable(int, ?Throwable): T $work The unit; called with the attempt number and the previous failure
     *
     * @throws ConcurrencyConflict When every attempt met a lock conflict (nothing of the unit is pending)
     * @throws Throwable Any other failure of the work, unchanged, at once
     *
     * @return T What the work returned
     */
    public function run(callable $work): mixed;
}
