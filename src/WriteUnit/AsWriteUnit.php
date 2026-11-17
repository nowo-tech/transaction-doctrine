<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Attribute;

/**
 * Runs the controller (class or action) as one write unit: replayed after a deadlock or a lock wait timeout, its
 * arguments resolved again on a replay (NowoTransactionDoctrineBundle's WriteUnitControllerListener, option
 * `controller_attribute`). Bundle controllers the host cannot annotate get the same through `controller_routes`.
 *
 * Only for actions that are replayable as a whole. **The action must commit at most once, or be idempotent as a
 * whole**: a replay runs it again from the start (with commit detection on, a failed attempt that already committed is
 * not replayed and answers the conflict instead). Side effects (mails, messages, pushes) after the commit, and no
 * entity loaded before the controller (a request listener's memo) written into a new association. On a replay only the
 * Doctrine entity arguments are reloaded (by identifier); every other argument (request DTOs, uploaded files, the
 * request) keeps its first-attempt value.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class AsWriteUnit
{
}
