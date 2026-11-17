<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\Conflict;

use Throwable;

/**
 * Exception-mapping hook: tells the conflict responder that a failure it does not know is a lock conflict the client
 * may retry, so it answers the configured status (409 by default) with `Retry-After` on any route. Hosts and bundles
 * map their own exceptions this way instead of matching route names. Autoconfigured with the
 * `nowo_transaction_doctrine.conflict_exception_mapper` tag. Implementations must be stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface ConflictExceptionMapperInterface
{
    public const TAG = 'nowo_transaction_doctrine.conflict_exception_mapper';

    public function isConflict(Throwable $failure): bool;
}
