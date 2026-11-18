<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

/**
 * A per-request (or per-message) memo that must be emptied before a write unit replays its work.
 *
 * Between two attempts a write unit rolls back what the failed attempt left open and clears (or resets) every entity
 * manager: an entity a memo kept from the failed attempt is detached afterwards. Reused in the replay it either fails
 * the flush (`ORMInvalidArgumentException`, a 500 instead of the conflict answer) or is silently ignored (changes to a
 * detached entity are never written). **A request memo that holds entities must implement this interface, or keep
 * identifiers instead of entities.**
 *
 * The Symfony bundle autoconfigures every service implementing it with the tag
 * `nowo_transaction_doctrine.reset_between_attempts`; WriteUnit calls resetBetweenAttempts() on each, in tag priority
 * order (higher first, then registration order), for every entry point (#[AsWriteUnit], `controller_routes`, the
 * Messenger middleware and the programmatic WriteUnitInterface service):
 *
 * - after the failed attempt's entity managers were cleared or reset and the pause, right before the next attempt;
 * - never before the first attempt, and never after the last failure (no replay follows);
 * - an exception thrown here aborts the replay: the unit gives up with ConcurrencyConflict (the attempt's failure as
 *   previous, this exception as ConcurrencyConflict::$resetFailure).
 *
 * Implementations stay cheap and idempotent (drop the memo; reload lazily on the next read). The same service usually
 * also implements Symfony's ResetInterface (`kernel.reset`), which empties it between requests.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface ResetBetweenAttemptsInterface
{
    public const TAG = 'nowo_transaction_doctrine.reset_between_attempts';

    /** Forgets every entity (and anything derived from one) kept since the request or message started. */
    public function resetBetweenAttempts(): void;
}
