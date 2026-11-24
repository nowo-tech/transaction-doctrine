<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Nowo\TransactionContract\ConflictCode as ContractConflictCode;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * The platform's conflict code (REQ-RUNTIME-003 rule 6), carried by every conflict answer next to the 409 (or 503):
 *
 * - CONFLICT_001: nothing was committed, retrying is safe; the answer has `Retry-After`;
 * - CONFLICT_002: part of the request may already be committed (ConcurrencyConflict::$committed anywhere in the
 *   failure, a Messenger wrapper after a handler already succeeded, or a lost savepoint with no deadlock behind it:
 *   the outcome is unknown); read again, do not repeat; no `Retry-After`.
 *
 * "Part committed" covers the whole request, not only the failed unit: forRequest() also answers CONFLICT_002 when
 * anything was committed since the request's CommitMark (taken at kernel.request on the main request when
 * `commit_detection` is on; transaction-doctrine >= 1.6).
 *
 * Bundles answering in their own shape (JSON-RPC, MCP tools, a REST API's error body) use the same values: the
 * constants for attributes and string contexts, the cases everywhere else; and the same values in the
 * `X-Conflict-Code` header (Symfony\Conflict\ConflictResponsePolicy::headersFor()). Stateless.
 *
 * @deprecated since transaction-doctrine 1.10, removed in 2.0: name the framework-free
 *             Nowo\TransactionContract\ConflictCode (same cases, constants and values) and work out a failure's code
 *             with Nowo\TransactionDoctrine\ConflictCodeResolver::forFailure() / forRequest(). toContract() and
 *             fromContract() convert between the two enums; every transaction-doctrine API that takes a code accepts
 *             both.
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
     * The code of a conflict failure: ConflictCodeResolver::forFailure(), as this (deprecated) enum.
     *
     * @param CommitMark|null $since A watermark taken before the work that failed (null = the failure alone decides)
     */
    public static function forFailure(Throwable $failure, ?CommitMark $since = null): self
    {
        return self::fromContract(ConflictCodeResolver::forFailure($failure, $since));
    }

    /**
     * The code of a conflict met while handling $request: ConflictCodeResolver::forRequest(), as this (deprecated)
     * enum.
     */
    public static function forRequest(Request $request, Throwable $failure): self
    {
        return self::fromContract(ConflictCodeResolver::forRequest($request, $failure));
    }

    /**
     * This enum's case for a code of either enum (same value).
     */
    public static function fromContract(ContractConflictCode|self $code): self
    {
        return $code instanceof self ? $code : self::from($code->value);
    }

    /**
     * The framework-free contract case with the same value.
     */
    public function toContract(): ContractConflictCode
    {
        return ContractConflictCode::from($this->value);
    }

    /** Whether the answer may invite a retry (and carry `Retry-After`). */
    public function retrySafe(): bool
    {
        return $this === self::RetrySafe;
    }
}
