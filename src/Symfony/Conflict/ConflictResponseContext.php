<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\Conflict;

use Nowo\TransactionDoctrine\ConflictCode;
use Throwable;

/**
 * What a conflict formatter needs to build its answer. Immutable value object, built per answer.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ConflictResponseContext
{
    /** The conflict code (REQ-RUNTIME-003 rule 6): CONFLICT_002 when committed, else CONFLICT_001. */
    public ConflictCode $code;

    /**
     * @param int $status The status the answer gets (409 or 503)
     * @param int|null $retryAfterSeconds The `Retry-After` the answer gets; null (no header) when part of the unit
     *                                    was committed: a blind retry could repeat it
     * @param string $errorCode The stable JSON error key (`conflict_response.json_error`, `_partial` when committed)
     * @param string $message The translated, client-safe explanation
     * @param int|null $attempts Attempts of the write unit, when the failure is a ConcurrencyConflict
     * @param bool $committed Whether part of the unit was already committed (ConcurrencyConflict::$committed): answer
     *                        "reload before trying again", never invite a blind retry
     * @param ConflictCode|null $code The conflict code; null = derived from $committed (CONFLICT_002 when true)
     */
    public function __construct(
        public int $status,
        public ?int $retryAfterSeconds,
        public string $errorCode,
        public string $message,
        public ?int $attempts,
        public Throwable $failure,
        public bool $committed = false,
        ?ConflictCode $code = null,
    ) {
        $this->code = $code ?? ($committed ? ConflictCode::Committed : ConflictCode::RetrySafe);
    }
}
