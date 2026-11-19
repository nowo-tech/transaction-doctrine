<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\Conflict;

use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Throwable;

/**
 * Opt-in mapper (`conflict_response.map_transient_failures: true`): every transient storage failure that reaches the
 * kernel (the contract's TransientStorageFailure, or a deadlock / lock wait / serialization failure no runner wrapped)
 * is answered as a conflict instead of a 500 (CONFLICT_001), and so is a lost savepoint with no deadlock behind it
 * (TransientFailureClassifier::isCommitOutcomeUnknown(): CONFLICT_002, no Retry-After). Unique violations and lost
 * connections are never mapped. Since 1.7 it also looks inside Messenger wrappers (a deadlock in the second handler
 * of a synchronous HandlerFailedException), through TransientFailureClassifier::findTransientFailure(). Off by default, because the responder runs before the bundles' own
 * mappers and would take over their JSON shapes. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class TransientFailureConflictMapper implements ConflictExceptionMapperInterface
{
    public function isConflict(Throwable $failure): bool
    {
        return TransientFailureClassifier::findTransientFailure($failure) instanceof Throwable;
    }
}
