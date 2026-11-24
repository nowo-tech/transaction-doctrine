<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Nowo\TransactionContract\ConcurrencyConflict;
use Nowo\TransactionContract\ConflictCode;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

use function interface_exists;

/**
 * Works out the conflict code (REQ-RUNTIME-003 rule 6) of a failure, as the framework-free
 * Nowo\TransactionContract\ConflictCode (transaction-doctrine >= 1.10):
 *
 * - CONFLICT_001: nothing was committed, retrying is safe; the answer has `Retry-After`;
 * - CONFLICT_002: part of the request may already be committed (ConcurrencyConflict::$committed anywhere in the
 *   failure, a Messenger wrapper after a handler already succeeded, or a lost savepoint with no deadlock behind it:
 *   the outcome is unknown); read again, do not repeat; no `Retry-After`.
 *
 * This is the Doctrine / Symfony half that the deprecated Nowo\TransactionDoctrine\ConflictCode carried in its
 * static methods (which now delegate here): the contract enum holds the values only, so a bundle's domain or contract
 * layer can name it without depending on HttpFoundation or Messenger. The HTTP answer (status, `X-Conflict-Code`,
 * `Retry-After`) stays in Symfony\Conflict\ConflictResponsePolicy. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConflictCodeResolver
{
    /**
     * The code of a conflict failure. CONFLICT_002 when:
     *
     * - $since saw a commit after it was taken;
     * - anything the failure carries proves a commit (transaction-doctrine >= 1.6.1): a ConcurrencyConflict with
     *   `committed` true anywhere along getPrevious() and, when symfony/messenger is installed, inside a Messenger
     *   wrapper's getWrappedExceptions(); or a Messenger wrapper that cannot be replayed (a HandlerFailedException
     *   whose envelope carries a HandledStamp: another handler already succeeded; any DelayedMessageHandlingException:
     *   the outer dispatch, and its transaction, already committed);
     * - otherwise, the first ConcurrencyConflict of the getPrevious() chain says it committed, or there is none and the
     *   chain (or, transaction-doctrine >= 1.7, the chain of an exception a Messenger wrapper carries) holds a lost
     *   savepoint with no transient failure (commit outcome unknown, TransientFailureClassifier::isCommitOutcomeUnknown());
     * - the walk over what the failure carries was cut short by its cap (more than ThrowableWalk::MAX_NODES throwables,
     *   transaction-doctrine >= 1.7): a proof may sit beyond it, so the safe answer is CONFLICT_002.
     *
     * CONFLICT_001 otherwise. Stateless; works without symfony/messenger (the Messenger rules are then skipped).
     *
     * @param CommitMark|null $since A watermark taken before the work that failed (null = the failure alone decides)
     */
    public static function forFailure(Throwable $failure, ?CommitMark $since = null): ConflictCode
    {
        if (($since instanceof CommitMark && $since->committedSince()) || self::carriesCommit($failure) || self::wrappedOutcomeUnknown($failure)) {
            return ConflictCode::Committed;
        }

        // A ConcurrencyConflict decides its own chain only (audit UI-2): the wrapped chains were read above.
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConcurrencyConflict) {
                return $current->committed ? ConflictCode::Committed : ConflictCode::RetrySafe;
            }
        }

        return (new TransientFailureClassifier())->isCommitOutcomeUnknown($failure) ? ConflictCode::Committed : ConflictCode::RetrySafe;
    }

    /**
     * The code of a conflict met while handling $request: forFailure() with the request's CommitMark (attribute
     * CommitMark::REQUEST_ATTRIBUTE; a sub-request carries the main request's). Without a mark (commit detection
     * off, or the request never went through kernel.request) the failure alone decides.
     */
    public static function forRequest(Request $request, Throwable $failure): ConflictCode
    {
        $mark = $request->attributes->get(CommitMark::REQUEST_ATTRIBUTE);

        return self::forFailure($failure, $mark instanceof CommitMark ? $mark : null);
    }

    /**
     * Whether the failure, or anything it carries (getPrevious() and Messenger's wrapped exceptions), proves that part
     * of the work committed (the bounded ThrowableWalk). A walk truncated by its cap answers true: a proof may sit
     * beyond it, and CONFLICT_002 is the safe over-approximation (audit TA42-1). Anything a custom wrapper returns that
     * is not a Throwable is ignored.
     */
    private static function carriesCommit(Throwable $failure): bool
    {
        $messenger = interface_exists(WrappedExceptionsInterface::class);
        $walk      = ThrowableWalk::of($failure);

        foreach ($walk as $current => $chainStart) {
            if (($current instanceof ConcurrencyConflict && $current->committed) || ($messenger && self::isNonReplayableWrapper($current))) {
                return true;
            }
        }

        return $walk->getReturn();
    }

    /**
     * Whether the chain of an exception a Messenger wrapper carries (not the failure's own getPrevious() chain) is a
     * lost savepoint with no transient failure (TransientFailureClassifier::isCommitOutcomeUnknown()). A chain that
     * holds a ConcurrencyConflict is decided by that conflict (its `committed`, read by carriesCommit()), so it is
     * skipped; the other chains are read whatever the first wrapped exception was (audit UI-2: a first handler's
     * uncommitted conflict no longer hides a second handler's lost savepoint).
     */
    private static function wrappedOutcomeUnknown(Throwable $failure): bool
    {
        $classifier = new TransientFailureClassifier();

        foreach (ThrowableWalk::of($failure) as $current => $chainStart) {
            if ($chainStart && $current !== $failure && !self::chainHasConflict($current) && $classifier->isCommitOutcomeUnknown($current)) {
                return true;
            }
        }

        return false;
    }

    private static function chainHasConflict(Throwable $start): bool
    {
        for ($current = $start; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConcurrencyConflict) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Messenger wrapper that proves earlier work completed: a HandlerFailedException whose envelope carries a
     * HandledStamp, or a DelayedMessageHandlingException. Only called when symfony/messenger is installed.
     */
    private static function isNonReplayableWrapper(Throwable $throwable): bool
    {
        return $throwable instanceof DelayedMessageHandlingException
            || ($throwable instanceof HandlerFailedException && $throwable->getEnvelope()->all(HandledStamp::class) !== []);
    }
}
