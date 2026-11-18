<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * The platform's conflict code (REQ-RUNTIME-003 rule 6), carried by every conflict answer next to the 409 (or 503):
 *
 * - CONFLICT_001: nothing was committed, retrying is safe; the answer has `Retry-After`;
 * - CONFLICT_002: part of the request may already be committed (ConcurrencyConflict::$committed, or a lost savepoint
 *   with no deadlock behind it: the outcome is unknown); read again, do not repeat; no `Retry-After`.
 *
 * "Part committed" covers the whole request, not only the failed unit: forRequest() also answers CONFLICT_002 when
 * anything was committed since the request's CommitMark (taken at kernel.request on the main request when
 * `commit_detection` is on; transaction-doctrine >= 1.6).
 *
 * Bundles answering in their own shape (JSON-RPC, MCP tools, a REST API's error body) use the same values: the
 * constants for attributes and string contexts, the cases everywhere else; and the same values in the
 * `X-Conflict-Code` header (Symfony\Conflict\ConflictResponsePolicy::headersFor()). Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
enum ConflictCode: string
{
    public const RETRY_SAFE = 'CONFLICT_001';
    public const COMMITTED  = 'CONFLICT_002';

    /** Nothing was committed: the client may retry after `Retry-After`. */
    case RetrySafe = 'CONFLICT_001';

    /** Part of the work may already be committed: read again, do not repeat blindly. */
    case Committed = 'CONFLICT_002';

    /**
     * The code of a conflict failure: CONFLICT_002 when the first ConcurrencyConflict of the chain says it committed,
     * or when there is none and the chain holds a lost savepoint with no transient failure (commit outcome unknown,
     * TransientFailureClassifier::isCommitOutcomeUnknown()), or when $since saw a commit after it was taken;
     * CONFLICT_001 otherwise.
     *
     * @param CommitMark|null $since A watermark taken before the work that failed (null = the failure alone decides)
     */
    public static function forFailure(Throwable $failure, ?CommitMark $since = null): self
    {
        if ($since instanceof CommitMark && $since->committedSince()) {
            return self::Committed;
        }

        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConcurrencyConflict) {
                return $current->committed ? self::Committed : self::RetrySafe;
            }
        }

        return (new TransientFailureClassifier())->isCommitOutcomeUnknown($failure) ? self::Committed : self::RetrySafe;
    }

    /**
     * The code of a conflict met while handling $request: forFailure() with the request's CommitMark (attribute
     * CommitMark::REQUEST_ATTRIBUTE; a sub-request carries the main request's). Without a mark (commit detection
     * off, or the request never went through kernel.request) the failure alone decides.
     */
    public static function forRequest(Request $request, Throwable $failure): self
    {
        $mark = $request->attributes->get(CommitMark::REQUEST_ATTRIBUTE);

        return self::forFailure($failure, $mark instanceof CommitMark ? $mark : null);
    }

    /** Whether the answer may invite a retry (and carry `Retry-After`). */
    public function retrySafe(): bool
    {
        return $this === self::RetrySafe;
    }
}
