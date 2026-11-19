<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use LogicException;
use Nowo\TransactionDoctrine\Tests\Unit\Support\CyclicWrapper;
use Nowo\TransactionDoctrine\ThrowableWalk;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * ThrowableWalk::leaves() (audit UI2-4): a Messenger wrapper's exceptions flattened like getWrappedExceptions(null,
 * true), but bounded and never throwing.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ThrowableWalkLeavesTest extends TestCase
{
    public function testNestedWrappersAreFlattenedInOrderAsMessengerDoes(): void
    {
        $a       = new RuntimeException('a');
        $b       = new LogicException('b');
        $c       = new RuntimeException('c');
        $inner   = new HandlerFailedException(new Envelope(new stdClass()), [$b, $c]);
        $failure = new HandlerFailedException(new Envelope(new stdClass()), [$a, $inner]);

        self::assertSame([$a, $b, $c], ThrowableWalk::leaves($failure));
        self::assertSame($failure->getWrappedExceptions(null, true), ThrowableWalk::leaves($failure));
    }

    public function testAnEmptyWrapperHasNoLeaves(): void
    {
        self::assertSame([], ThrowableWalk::leaves(new CyclicWrapper()));
    }

    /** A wrapper that wraps itself, or an ancestor, ends: each throwable once. */
    public function testACycleOfWrappersEnds(): void
    {
        $leaf           = new RuntimeException('leaf');
        $outer          = new CyclicWrapper();
        $inner          = new CyclicWrapper();
        $outer->wrapped = [$outer, $inner, $leaf];
        $inner->wrapped = [$outer, $inner, $leaf, new LogicException('other')];

        $leaves = ThrowableWalk::leaves($outer);

        // The leaf is kept where it was first met (among the outer wrapper's children, after the inner wrapper).
        self::assertNotNull($leaves);
        self::assertCount(2, $leaves);
        self::assertSame('other', $leaves[0]->getMessage());
        self::assertSame($leaf, $leaves[1]);
    }

    public function testAThrowingWrapperAnywhereMakesTheListUnreadable(): void
    {
        $broken         = new CyclicWrapper();
        $broken->throws = true;

        self::assertNull(ThrowableWalk::leaves($broken));

        $outer          = new CyclicWrapper();
        $outer->wrapped = [new RuntimeException('a'), $broken];
        self::assertNull(ThrowableWalk::leaves($outer));
    }

    public function testANonThrowableMakesTheListUnreadable(): void
    {
        $wrapper          = new CyclicWrapper();
        $wrapper->wrapped = [new RuntimeException('a'), 'not a throwable'];

        self::assertNull(ThrowableWalk::leaves($wrapper));
    }

    /** More than MAX_NODES throwables (wide or deep) is not read: null, never a partial list. */
    public function testTheCapMakesTheListUnreadable(): void
    {
        $wide = new CyclicWrapper();
        for ($i = 1; $i < ThrowableWalk::MAX_NODES; ++$i) {
            $wide->wrapped[] = new RuntimeException('x' . $i);
        }

        // The wrapper and its 255 children: exactly the cap.
        self::assertCount(ThrowableWalk::MAX_NODES - 1, ThrowableWalk::leaves($wide) ?? []);

        $wide->wrapped[] = new RuntimeException('one too many');
        self::assertNull(ThrowableWalk::leaves($wide));

        $deep          = new CyclicWrapper();
        $deep->wrapped = [new RuntimeException('deepest')];
        for ($i = 0; $i < ThrowableWalk::MAX_NODES + 10; ++$i) {
            $outer          = new CyclicWrapper();
            $outer->wrapped = [$deep];
            $deep           = $outer;
        }

        self::assertNull(ThrowableWalk::leaves($deep));
    }
}
