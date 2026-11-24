<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\Conflict;

use InvalidArgumentException;
use Nowo\TransactionContract\ConflictCode as ContractConflictCode;
use Nowo\TransactionDoctrine\ConflictCode;
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
     * The platform's conflict-code header (REQ-RUNTIME-003 rule 6, audit TH X-2): `CONFLICT_001` or `CONFLICT_002`
     * only. Cross-origin APIs list it in `Access-Control-Expose-Headers`.
     */
    public const CODE_HEADER = 'X-Conflict-Code';

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
     * The `Retry-After` header alone. Kept for BC: prefer headersFor(), which also sends `X-Conflict-Code` and drops
     * `Retry-After` for CONFLICT_002.
     *
     * @return array{Retry-After: string}
     */
    public function headers(?int $hint = null): array
    {
        return ['Retry-After' => (string) $this->retryAfter($hint)];
    }

    /**
     * The headers of a conflict answer with code $code: `X-Conflict-Code` always, `Retry-After` only for CONFLICT_001
     * (a CONFLICT_002 answer must not invite a blind retry, whatever the failure's hint says).
     *
     * @param ConflictCode|ContractConflictCode $code Either enum (transaction-doctrine >= 1.10 accepts the contract's)
     * @param int|null $hint A failure's own hint; null = the configured value
     *
     * @return array{X-Conflict-Code: string, Retry-After?: string}
     */
    public function headersFor(ConflictCode|ContractConflictCode $code, ?int $hint = null): array
    {
        if (!$code->retrySafe()) {
            return [self::CODE_HEADER => $code->value];
        }

        return [self::CODE_HEADER => $code->value, 'Retry-After' => (string) $this->retryAfter($hint)];
    }

    /**
     * An HTTP exception with the policy's status and headersFor($code), for routes rendered by the host's error page:
     * the page then carries the code in `X-Conflict-Code` even when its body has none.
     *
     * @param ConflictCode|ContractConflictCode $code Either enum (transaction-doctrine >= 1.10 accepts the contract's)
     * @param string $message Generic, client-safe text
     */
    public function httpExceptionFor(ConflictCode|ContractConflictCode $code, string $message, ?Throwable $previous = null, ?int $hint = null): HttpException
    {
        return new HttpException($this->status, $message, $previous, $this->headersFor($code, $hint));
    }

    /**
     * An HTTP exception with the policy's status and `Retry-After`, for HTML routes rendered by the host's error page.
     * Kept for BC: prefer httpExceptionFor(), which also sends `X-Conflict-Code` and drops `Retry-After` for
     * CONFLICT_002.
     *
     * @param string $message Generic, client-safe text
     */
    public function httpException(string $message, ?Throwable $previous = null, ?int $hint = null): HttpException
    {
        return new HttpException($this->status, $message, $previous, $this->headers($hint));
    }
}
