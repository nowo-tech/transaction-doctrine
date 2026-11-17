<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\Messenger;

use RuntimeException;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * A dispatch WriteUnitMiddleware must not replay in-process (a handler of the message already succeeded, or the failure
 * came from messages dispatched after the handler): the original failure travels in a property, not as the previous
 * exception, so the write unit sees no lock conflict in it and the middleware rethrows the original at once.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class UnreplayableDispatch extends RuntimeException
{
    public function __construct(public readonly HandlerFailedException|DelayedMessageHandlingException $failure)
    {
        parent::__construct('A dispatch that is not replayed in-process.');
    }
}
