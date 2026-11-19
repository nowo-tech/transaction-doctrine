<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Tests\Unit\Support\CyclicWrapper;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
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

use function array_fill;

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

        self::assertSame(ConflictCode::RetrySafe, $this->code($wrapper));

        $wrapper->wrapped[] = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true);
        self::assertSame(ConflictCode::Committed, $this->code($wrapper));
    }

    /** Audit TA4-4: a custom wrapper returning something that is not a Throwable is ignored, never a TypeError. */
    public function testNonThrowablesFromACustomWrapperAreIgnored(): void
    {
        $wrapper          = new CyclicWrapper();
        $wrapper->wrapped = [null, 'not a throwable', new stdClass(), 42, new RuntimeException('a')];

        self::assertSame(ConflictCode::RetrySafe, $this->code($wrapper));

        $wrapper->wrapped[] = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true);
        self::assertSame(ConflictCode::Committed, $this->code($wrapper));
    }

    /**
     * Audit TA4-3: the queue stops growing once it holds what the walk may still visit. Audit TA42-1 (1.7): the
     * truncated walk answers CONFLICT_002, since a proof may sit beyond the cap.
     */
    public function testTheQueueIsBoundedToo(): void
    {
        $leaf  = new CyclicWrapper();
        $outer = new CyclicWrapper();
        for ($i = 0; $i < 1000; ++$i) {
            $outer->wrapped[] = new RuntimeException('x' . $i);
        }

        $outer->wrapped[] = $leaf;
        $leaf->wrapped    = [new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true)];

        // Only the first 255 children are queued; the committed conflict behind the 1001st is never reached, so the
        // walk ends truncated: CONFLICT_002 (the safe over-approximation).
        self::assertSame(ConflictCode::Committed, $this->code($outer));

        // Under the cap nothing is truncated, and nothing proves a commit.
        $outer->wrapped = [new RuntimeException('a'), new RuntimeException('b')];
        self::assertSame(ConflictCode::RetrySafe, $this->code($outer));
    }

    /**
     * The walk is bounded: a proof beyond 256 throwables is not looked for (the truncated walk answers CONFLICT_002,
     * 1.7); one among the first is found.
     */
    public function testTheWalkIsBounded(): void
    {
        $committed = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true);
        $plain     = [];
        for ($i = 0; $i < 300; ++$i) {
            $plain[] = new RuntimeException('x' . $i);
        }

        $wrapper          = new CyclicWrapper();
        $wrapper->wrapped = [...$plain, $committed];
        self::assertSame(ConflictCode::Committed, $this->code($wrapper));

        $wrapper->wrapped = $plain;
        self::assertSame(ConflictCode::Committed, $this->code($wrapper));

        $wrapper->wrapped = [$committed, ...$plain];
        self::assertSame(ConflictCode::Committed, $this->code($wrapper));

        $wrapper->wrapped = [new RuntimeException('a'), new RuntimeException('b'), $committed];
        self::assertSame(ConflictCode::Committed, $this->code($wrapper));
    }

    /**
     * Audit TA42-2 (1.7): a child already known is never queued again, so duplicates do not use up the cap (a
     * HandlerFailedException's previous is also its first wrapped exception).
     */
    public function testDuplicatesDoNotUseUpTheCap(): void
    {
        $same             = new RuntimeException('same');
        $wrapper          = new CyclicWrapper();
        $wrapper->wrapped = [...array_fill(0, 1000, $same), new RuntimeException('b')];

        self::assertSame(ConflictCode::RetrySafe, $this->code($wrapper));

        $wrapper->wrapped[] = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true);
        self::assertSame(ConflictCode::Committed, $this->code($wrapper));
    }

    /**
     * Audits TA42-3 / UA2-5 (1.7): a lost savepoint (commit outcome unknown) in a later wrapped exception answers
     * CONFLICT_002, as it does in the getPrevious() chain; a unique violation behind it does not.
     */
    public function testALostSavepointInALaterWrappedExceptionIsCommitted(): void
    {
        $envelope = new Envelope(new stdClass());

        self::assertSame(ConflictCode::Committed, $this->code(new HandlerFailedException($envelope, [new RuntimeException('business rule'), new RuntimeException('wrap', 0, FakeDriverException::lostSavepoint())])));
        self::assertSame(ConflictCode::RetrySafe, $this->code(new HandlerFailedException($envelope, [new RuntimeException('business rule'), FakeDriverException::deadlock()])));
        self::assertSame(ConflictCode::RetrySafe, $this->code(new HandlerFailedException($envelope, [new RuntimeException('business rule'), new FakeDriverException('SQLSTATE[42000]: 1305 SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305, FakeDriverException::uniqueViolation())])));
    }

    /**
     * Audit UI-2 (1.7): a first handler's uncommitted ConcurrencyConflict decides its own chain only; a second
     * handler's lost savepoint (MySQL / MariaDB 1305, PostgreSQL 3B001) still answers CONFLICT_002.
     */
    public function testAnUncommittedConflictDoesNotHideALaterLostSavepoint(): void
    {
        $envelope = new Envelope(new stdClass());
        $conflict = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, FakeDriverException::deadlock());

        self::assertSame(ConflictCode::Committed, $this->code(new HandlerFailedException($envelope, [$conflict, FakeDriverException::lostSavepoint()])));
        self::assertSame(ConflictCode::Committed, $this->code(new HandlerFailedException($envelope, [$conflict, new FakeDriverException('savepoint "DOCTRINE_2" does not exist', '3B001', 7)])));
        // A lost savepoint under a conflict in its own chain: the conflict decides that chain.
        $second = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, FakeDriverException::lostSavepoint());
        self::assertSame(ConflictCode::RetrySafe, $this->code(new HandlerFailedException($envelope, [$conflict, $second])));
    }

    /** Audit UI-5 (1.7): a custom wrapper whose getWrappedExceptions() throws never escapes; it counts as truncated. */
    public function testABrokenWrapperNeverThrowsOutOfTheWalk(): void
    {
        $wrapper         = new CyclicWrapper();
        $wrapper->throws = true;

        self::assertSame(ConflictCode::Committed, $this->code($wrapper));
        self::assertNull(TransientFailureClassifier::findConflict($wrapper));
        self::assertNull(TransientFailureClassifier::findLockConflict(new RuntimeException('outer', 0, $wrapper)));
    }

    /** @phpstan-impure the answer depends on the wrapper, which the test changes between calls */
    private function code(Throwable $failure): ConflictCode
    {
        return ConflictCode::forFailure($failure);
    }
}
