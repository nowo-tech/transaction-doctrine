<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Attribute;

/**
 * Runs the controller (class or action) as one write unit: replayed after a deadlock or a lock wait timeout, its
 * arguments resolved again on a replay (NowoTransactionDoctrineBundle's WriteUnitControllerListener, option
 * `controller_attribute`). Bundle controllers the host cannot annotate get the same through `controller_routes`.
 *
 * Only for actions that are replayable as a whole: side effects (mails, messages, pushes) after the commit, and no
 * entity loaded before the controller (a request listener's memo) written into a new association.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class AsWriteUnit
{
}
