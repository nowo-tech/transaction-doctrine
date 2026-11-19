<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use LogicException;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\ThrowableWalk;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The bounded walk over the getPrevious() chain (1.7). Runs without symfony/messenger; the wrapped exceptions are
 * covered by ConflictCodeMessengerTest and TransientFailureClassifierMessengerTest.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ThrowableWalkTest extends TestCase
{
    public function testTheChainIsWalkedInOrderEachOneStartingNoChainButTheFirst(): void
    {
        $deepest = new LogicException('c');
        $middle  = new RuntimeException('b', 0, $deepest);
        $failure = new RuntimeException('a', 0, $middle);
        $walk    = ThrowableWalk::of($failure);
        $seen    = [];

        foreach ($walk as $current => $chainStart) {
            $seen[] = [$current, $chainStart];
        }

        self::assertSame([[$failure, true], [$middle, false], [$deepest, false]], $seen);
        self::assertFalse($walk->getReturn());
    }

    public function testFirstFindsTheFirstInstanceOfAClass(): void
    {
        $conflict = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 2, FakeDriverException::deadlock());
        $failure  = new RuntimeException('a', 0, new LogicException('b', 0, $conflict));

        self::assertSame($conflict, ThrowableWalk::first($failure, ConcurrencyConflict::class));
        self::assertNull(ThrowableWalk::first(new RuntimeException('a'), ConcurrencyConflict::class));
    }

    /** A chain longer than the cap is cut, and the walk says so. */
    public function testALongChainIsTruncated(): void
    {
        $failure = new RuntimeException('0');
        for ($i = 1; $i <= ThrowableWalk::MAX_NODES + 10; ++$i) {
            $failure = new RuntimeException((string) $i, 0, $failure);
        }

        $walk  = ThrowableWalk::of($failure);
        $count = 0;
        foreach ($walk as $current => $chainStart) {
            self::assertSame($count === 0, $chainStart, $current->getMessage());
            ++$count;
        }

        self::assertSame(ThrowableWalk::MAX_NODES, $count);
        self::assertTrue($walk->getReturn());
    }
}
