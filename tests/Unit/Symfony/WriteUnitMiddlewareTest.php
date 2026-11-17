<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Nowo\TransactionDoctrine\Symfony\Messenger\WriteUnitMiddleware;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\Tests\Unit\Support\RecordingRegistry;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WriteUnitMiddlewareTest extends TestCase
{
    public function testAConsumedMessageIsReplayedAfterADeadlock(): void
    {
        $handler = new ScriptedHandler([HandlerFailedException::class, 'ok']);
        $result  = $this->dispatch($this->consumed(), $handler);

        self::assertSame(2, $handler->calls);
        self::assertNotNull($result->last(HandledStamp::class));
    }

    public function testAMessageHandledInsideARequestIsLeftToTheRequest(): void
    {
        $handler = new ScriptedHandler([HandlerFailedException::class]);

        $this->expectException(HandlerFailedException::class);

        try {
            $this->dispatch(new Envelope(new stdClass()), $handler);
        } finally {
            self::assertSame(1, $handler->calls);
        }
    }

    public function testTheLastConflictGoesOutInsideAHandlerFailedException(): void
    {
        $handler = new ScriptedHandler([HandlerFailedException::class, HandlerFailedException::class, HandlerFailedException::class]);

        try {
            $this->dispatch($this->consumed(), $handler);
            self::fail('No failure.');
        } catch (HandlerFailedException $failure) {
            self::assertSame(3, $handler->calls);
            self::assertInstanceOf(ConcurrencyConflict::class, $failure->getWrappedExceptions()[0] ?? null);
            self::assertNotNull($failure->getEnvelope()->last(ConsumedByWorkerStamp::class));
        }
    }

    public function testAConflictOutsideAHandlerFailureKeepsTheOriginalEnvelope(): void
    {
        $handler  = new ScriptedHandler(['deadlock', 'deadlock', 'deadlock']);
        $envelope = $this->consumed();

        try {
            $this->dispatch($envelope, $handler);
            self::fail('No failure.');
        } catch (HandlerFailedException $failure) {
            self::assertSame($envelope, $failure->getEnvelope());
        }
    }

    public function testAMessageWithASucceededHandlerIsNotReplayedInProcess(): void
    {
        $handler = new ScriptedHandler(['partial']);

        try {
            $this->dispatch($this->consumed(), $handler);
            self::fail('No failure.');
        } catch (HandlerFailedException $failure) {
            self::assertSame(1, $handler->calls);
            self::assertNotSame([], $failure->getEnvelope()->all(HandledStamp::class));
        }
    }

    public function testADelayedDispatchFailureIsNotReplayedInProcess(): void
    {
        $handler = new ScriptedHandler(['delayed']);

        $this->expectException(DelayedMessageHandlingException::class);

        try {
            $this->dispatch($this->consumed(), $handler);
        } finally {
            self::assertSame(1, $handler->calls);
        }
    }

    /**
     * Audit SL-M4 (probe P5): a domain error next to a deadlock is not replayed and goes out unchanged.
     */
    public function testAMixedHandlerFailureIsNotReplayed(): void
    {
        $handler = new ScriptedHandler(['mixed', 'ok']);

        try {
            $this->dispatch($this->consumed(), $handler);
            self::fail('No failure.');
        } catch (HandlerFailedException $failure) {
            self::assertSame(1, $handler->calls);
            self::assertCount(2, $failure->getWrappedExceptions());
            self::assertInstanceOf(RuntimeException::class, $failure->getWrappedExceptions()[0]);
        }
    }

    public function testAnotherFailureIsRethrownUnchanged(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bug');

        $this->dispatch($this->consumed(), new ScriptedHandler(['bug']));
    }

    /** As MessageBus does: the stack's first middleware is the one handling the envelope. */
    private function dispatch(Envelope $envelope, MiddlewareInterface $handler): Envelope
    {
        $middleware = new WriteUnitMiddleware(new WriteUnit(new RecordingRegistry(), new WriteUnitPolicy(3, 0, 0, 0), null, new MockClock()));

        return $middleware->handle($envelope, new StackMiddleware([$middleware, $handler]));
    }

    private function consumed(): Envelope
    {
        return new Envelope(new stdClass(), [new ConsumedByWorkerStamp()]);
    }
}

/**
 * Plays one scripted outcome per call: a HandlerFailedException with a deadlock, a bare deadlock, a partial success,
 * a delayed-dispatch failure, a bug, or 'ok'.
 *
 * @internal
 */
final class ScriptedHandler implements MiddlewareInterface
{
    public int $calls = 0;

    /**
     * @param list<string> $script
     */
    public function __construct(private readonly array $script)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $step = $this->script[$this->calls] ?? 'ok';
        ++$this->calls;

        return match ($step) {
            HandlerFailedException::class => throw new HandlerFailedException($envelope, [FakeDriverException::deadlock()]),
            'deadlock'                    => throw FakeDriverException::deadlock(),
            'partial'                     => throw new HandlerFailedException($envelope->with(HandledStamp::fromDescriptor(new HandlerDescriptor(static fn (): null => null), null)), [FakeDriverException::deadlock()]),
            'delayed'                     => throw new DelayedMessageHandlingException([FakeDriverException::deadlock()], $envelope),
            'bug'                         => throw new RuntimeException('bug'),
            'mixed'                       => throw new HandlerFailedException($envelope, [new RuntimeException('business rule'), FakeDriverException::deadlock()]),
            default                       => $envelope->with(HandledStamp::fromDescriptor(new HandlerDescriptor(static fn (): null => null), 'done')),
        };
    }
}
