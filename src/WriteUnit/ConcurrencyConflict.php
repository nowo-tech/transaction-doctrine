<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Throwable;

/**
 * A write unit still met a lock conflict (deadlock, lock wait timeout, or another replayable kind) on its last attempt.
 * Nothing of it is pending. Unless `committed` is true (the unit gave up early because the failed attempt had already
 * committed, see WriteUnit), nothing of it was committed either, and the client (or Messenger's retry) may simply try
 * again.
 *
 * It is a TransientStorageFailure, so code that already handles the contract's transient failure keeps working; the
 * storage error is the previous exception. The message is constant and client-safe (no SQL, no parameters).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConcurrencyConflict extends TransientStorageFailure implements LockConflictExceptionInterface
{
    public const MESSAGE = 'The write met a database lock conflict on each attempt. Retry later.';

    /**
     * @param int $attempts Attempts the unit made (1 or more)
     * @param TransientFailureKind $kind Kind of the last conflict
     * @param int|null $retryAfterSeconds Seconds after which a retry is sensible; null = no hint
     * @param Throwable $previous The last attempt's failure
     * @param bool $committed Whether the failed attempt had already committed part of its writes (not replayed for
     *                        that reason; a client retry may repeat what was committed)
     */
    public function __construct(
        public readonly int $attempts,
        public readonly TransientFailureKind $kind,
        ?int $retryAfterSeconds,
        Throwable $previous,
        public readonly bool $committed = false,
    ) {
        parent::__construct($retryAfterSeconds, self::MESSAGE, $previous);
    }
}
