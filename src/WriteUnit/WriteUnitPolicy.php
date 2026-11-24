<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use InvalidArgumentException;
use Nowo\TransactionContract\TransientFailureKind;

use function in_array;
use function max;
use function min;

/**
 * How a write unit replays: at most $maxAttempts attempts, and a full-jitter pause between two of them, uniform between
 * the floor and min(cap, base × 2^(attempt − 1)) milliseconds. Only failures of the replay kinds are replayed.
 * Immutable value object.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class WriteUnitPolicy
{
    public const DEFAULT_MAX_ATTEMPTS = 3;
    public const DEFAULT_FLOOR_MS     = 20;
    public const DEFAULT_BASE_MS      = 100;
    public const DEFAULT_CAP_MS       = 200;
    public const DEFAULT_RETRY_AFTER  = 1;

    /** Kinds replayed by default: deadlock, lock wait timeout and (since 1.5.0) serialization failure. */
    public const DEFAULT_REPLAY_KINDS = [TransientFailureKind::Deadlock, TransientFailureKind::LockWaitTimeout, TransientFailureKind::SerializationFailure];

    /** @var list<TransientFailureKind> */
    public array $replayKinds;

    /**
     * @param list<string|TransientFailureKind>|null $replayKinds Kinds replayed (cases or their values); null = deadlock, lock wait timeout and serialization failure (1.5.0)
     * @param int|null $retryAfterSeconds Retry-After hint of the final ConcurrencyConflict
     *
     * @throws InvalidArgumentException When a bound is out of range
     */
    public function __construct(
        public int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        public int $floorMs = self::DEFAULT_FLOOR_MS,
        public int $baseMs = self::DEFAULT_BASE_MS,
        public int $capMs = self::DEFAULT_CAP_MS,
        ?array $replayKinds = null,
        public ?int $retryAfterSeconds = self::DEFAULT_RETRY_AFTER,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be 1 or more.');
        }

        if ($floorMs < 0 || $baseMs < 0 || $capMs < 0) {
            throw new InvalidArgumentException('Backoff milliseconds must be zero or more.');
        }

        if ($retryAfterSeconds !== null && $retryAfterSeconds < 0) {
            throw new InvalidArgumentException('retryAfterSeconds must be null or zero or more.');
        }

        $kinds = [];
        foreach ($replayKinds ?? self::DEFAULT_REPLAY_KINDS as $kind) {
            $kinds[] = $kind instanceof TransientFailureKind ? $kind : TransientFailureKind::from($kind);
        }

        $this->replayKinds = $kinds;
    }

    public function replays(TransientFailureKind $kind): bool
    {
        return in_array($kind, $this->replayKinds, true);
    }

    /**
     * @return array{0: int, 1: int} The pause bounds after the given failed attempt, in milliseconds
     */
    public function pauseBounds(int $attempt): array
    {
        $exponent = max(0, min(30, $attempt - 1));
        $ceiling  = min($this->capMs, $this->baseMs * (2 ** $exponent));

        return [$this->floorMs, max($this->floorMs, $ceiling)];
    }
}
