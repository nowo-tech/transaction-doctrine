<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\Conflict;

use Throwable;

/**
 * What a conflict formatter needs to build its answer. Immutable value object, built per answer.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ConflictResponseContext
{
    /**
     * @param int $status The status the answer gets (409 or 503)
     * @param int $retryAfterSeconds The `Retry-After` the answer gets
     * @param string $errorCode The stable JSON error code (`conflict_response.json_error`)
     * @param string $message The translated, client-safe explanation
     * @param int|null $attempts Attempts of the write unit, when the failure is a ConcurrencyConflict
     */
    public function __construct(
        public int $status,
        public int $retryAfterSeconds,
        public string $errorCode,
        public string $message,
        public ?int $attempts,
        public Throwable $failure,
    ) {
    }
}
