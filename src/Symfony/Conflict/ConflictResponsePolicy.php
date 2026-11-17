<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\Conflict;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

use function in_array;
use function max;

/**
 * The platform's answer to a lock conflict the client may retry: one status (409 Conflict by default, 503 Service
 * Unavailable for hosts that keep the 1.2 behaviour) and a `Retry-After` in seconds. Configured once through
 * `nowo_transaction_doctrine.conflict_response` and shared by the bundle's responder and by every bundle that answers
 * a transient storage failure in its own JSON shape (ChatKit, Wiki, TimeTrack…): they read the status from here, so
 * the whole application answers the same. Immutable value object.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ConflictResponsePolicy
{
    public const STATUS_CONFLICT            = 409;
    public const STATUS_SERVICE_UNAVAILABLE = 503;
    public const STATUSES                   = [self::STATUS_CONFLICT, self::STATUS_SERVICE_UNAVAILABLE];
    public const DEFAULT_RETRY_AFTER        = 1;

    /**
     * @throws InvalidArgumentException When the status is not 409 or 503, or Retry-After is negative
     */
    public function __construct(
        public int $status = self::STATUS_CONFLICT,
        public int $retryAfterSeconds = self::DEFAULT_RETRY_AFTER,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('The conflict status must be 409 or 503.');
        }

        if ($retryAfterSeconds < 0) {
            throw new InvalidArgumentException('retryAfterSeconds must be zero or more.');
        }
    }

    /**
     * @param int|null $hint A failure's own hint (TransientStorageFailure::retryAfterSeconds()); null = the configured value
     */
    public function retryAfter(?int $hint = null): int
    {
        return max(0, $hint ?? $this->retryAfterSeconds);
    }

    /**
     * @return array{Retry-After: string}
     */
    public function headers(?int $hint = null): array
    {
        return ['Retry-After' => (string) $this->retryAfter($hint)];
    }

    /**
     * An HTTP exception with the policy's status and `Retry-After`, for HTML routes rendered by the host's error page.
     *
     * @param string $message Generic, client-safe text
     */
    public function httpException(string $message, ?Throwable $previous = null, ?int $hint = null): HttpException
    {
        return new HttpException($this->status, $message, $previous, $this->headers($hint));
    }
}
