<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Throwable;

/**
 * Marks an exception as a lock conflict the client may simply retry. Anywhere in a failure's previous chain, it makes
 * the bundle's conflict responder answer the configured status (409 by default) with `Retry-After`, on any route:
 * hosts and bundles map their own exceptions without the responder matching route names. For exceptions a package
 * cannot change, register a ConflictExceptionMapperInterface service instead.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface LockConflictExceptionInterface extends Throwable
{
}
