<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Tests\Unit\Support\CyclicWrapper;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * ConflictCode::forFailure() with Messenger wrappers (1.6.1, audits TA-3 / TJ): a wrapper that proves earlier work
 * completed, and a committed ConcurrencyConflict inside getWrappedExceptions(), answer CONFLICT_002. Requires
 * symfony/messenger; ConflictCodeTest covers the rest and runs without it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConflictCodeMessengerTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, ConflictCode}>
     */
    public static function failures(): iterable
    {
        $envelope = new Envelope(new stdClass());
        $handled  = $envelope->with(HandledStamp::fromDescriptor(new HandlerDescriptor(static fn (): null => null), null));
        $conflict = static fn (bool $committed): ConcurrencyConflict => new ConcurrencyConflict(1, TransientFailureKind::Deadlock, $committed ? null : 1, FakeDriverException::deadlock(), $committed);

        yield 'handler failed, nothing handled' => [new HandlerFailedException($envelope, [$conflict(false)]), ConflictCode::RetrySafe];
        yield 'handler failed after another handler succeeded' => [new HandlerFailedException($handled, [$conflict(false)]), ConflictCode::Committed];
        yield 'handler failed after another handler succeeded, raw deadlock' => [new HandlerFailedException($handled, [FakeDriverException::deadlock()]), ConflictCode::Committed];
        yield 'delayed message handling' => [new DelayedMessageHandlingException([$conflict(false)], $envelope), ConflictCode::Committed];
        yield 'delayed message handling, wrapped' => [new RuntimeException('outer', 0, new DelayedMessageHandlingException([FakeDriverException::deadlock()], $envelope)), ConflictCode::Committed];
        yield 'committed conflict, first wrapped' => [new HandlerFailedException($envelope, [$conflict(true)]), ConflictCode::Committed];
        yield 'committed conflict, second wrapped' => [new HandlerFailedException($envelope, [new RuntimeException('business rule'), $conflict(true)]), ConflictCode::Committed];
        yield 'committed conflict, nested wrapper' => [new RuntimeException('outer', 0, new HandlerFailedException($envelope, [new RuntimeException('a'), new HandlerFailedException($envelope, [new RuntimeException('b'), $conflict(true)])])), ConflictCode::Committed];
        yield 'uncommitted conflicts only' => [new HandlerFailedException($envelope, [$conflict(false), $conflict(false)]), ConflictCode::RetrySafe];
    }

    #[DataProvider('failures')]
    public function testForFailure(Throwable $failure, ConflictCode $expected): void
    {
        self::assertSame($expected, ConflictCode::forFailure($failure));
    }

    public function testAWrapperCycleEnds(): void
    {
        $wrapper          = new CyclicWrapper();
        $wrapper->wrapped = [$wrapper, new RuntimeException('a')];

        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($wrapper));

        $wrapper->wrapped[] = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true);
        self::assertSame(ConflictCode::Committed, ConflictCode::forFailure($wrapper));
    }

    /** The walk is bounded: a proof beyond 256 throwables is not looked for. */
    public function testTheWalkIsBounded(): void
    {
        $committed        = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true);
        $wrapper          = new CyclicWrapper();
        $wrapper->wrapped = [$committed];
        for ($i = 0; $i < 300; ++$i) {
            $wrapper->wrapped[] = new RuntimeException('x' . $i);
        }

        // The last wrapped exception is walked first: the committed conflict lies beyond the bound.
        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($wrapper));

        $wrapper->wrapped = [new RuntimeException('a'), new RuntimeException('b'), $committed];
        self::assertSame(ConflictCode::Committed, ConflictCode::forFailure($wrapper));
    }
}
