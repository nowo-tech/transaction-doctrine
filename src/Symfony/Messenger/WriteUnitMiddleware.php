<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\Messenger;

use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Throwable;

/**
 * Handles every message a worker consumes as one write unit, so a deadlock or a lock wait timeout is replayed
 * in-process after a few milliseconds instead of waiting for the transport's retry delay. A replay is what a Messenger
 * retry already does (the handler runs again from the message); handlers that already succeeded on a multi-handler
 * message are never run twice (such a failure is not replayed in-process). Messages handled synchronously inside a web
 * request are left alone: the request owns that unit.
 *
 * After the last attempt the ConcurrencyConflict goes out inside a HandlerFailedException with the attempt's envelope,
 * so the transport's retry strategy and the failure transport see the usual shape. When part of the unit was already
 * committed (ConcurrencyConflict::$committed), it is wrapped in an UnrecoverableMessageHandlingException: the
 * transport does not retry it (a retry would repeat the committed part) and the message goes to the failure transport.
 *
 * Before a replay the write unit empties every ResetBetweenAttemptsInterface service (handler memos holding entities)
 * and dispatches WriteUnitRetryEvent with the message class as the unit.
 *
 * Add it to a bus before `doctrine_transaction` (service id `nowo_transaction_doctrine.write_unit_middleware`).
 * Stateless (FrankenPHP worker): the closure lives for one handle() call.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class WriteUnitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private WriteUnitInterface $writeUnit,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->last(ConsumedByWorkerStamp::class) instanceof StampInterface) {
            return $stack->next()->handle($envelope, $stack);
        }

        // The stack moves forward on each next(): every attempt walks a copy taken before the first.
        $start = clone $stack;

        try {
            $work = static function (int $attempt, ?Throwable $previous) use ($envelope, $start): Envelope {
                $retry        = $previous instanceof HandlerFailedException ? $previous->getEnvelope() : $envelope;
                $attemptStack = clone $start;

                try {
                    return $attemptStack->next()->handle($retry, $attemptStack);
                } catch (DelayedMessageHandlingException $delayed) {
                    throw new UnreplayableDispatch($delayed);
                } catch (HandlerFailedException $failed) {
                    if ($failed->getEnvelope()->all(HandledStamp::class) !== []) {
                        throw new UnreplayableDispatch($failed);
                    }

                    throw $failed;
                }
            };

            // WriteUnitInterface::run() takes no unit name before 2.0; the bundle's WriteUnit does.
            return $this->writeUnit instanceof WriteUnit ? $this->writeUnit->run($work, $envelope->getMessage()::class) : $this->writeUnit->run($work);
        } catch (ConcurrencyConflict $conflict) {
            $last = $conflict->getPrevious();

            // Part of the unit committed: a transport retry would repeat it, so the failure is unrecoverable.
            throw new HandlerFailedException($last instanceof HandlerFailedException ? $last->getEnvelope() : $envelope, [$conflict->committed ? new UnrecoverableMessageHandlingException(ConcurrencyConflict::MESSAGE, 0, $conflict) : $conflict]);
        } catch (UnreplayableDispatch $unreplayable) {
            throw $unreplayable->failure;
        }
    }
}
