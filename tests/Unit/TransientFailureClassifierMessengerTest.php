<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionDoctrine\Tests\Unit\Support\CyclicWrapper;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\ThrowableWalk;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * The static finders inside Messenger wrappers (1.7, audits TA42-3 / UA2-5): a conflict in a later wrapped exception
 * is found, through the bounded walk. Requires symfony/messenger.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransientFailureClassifierMessengerTest extends TestCase
{
    public function testAConflictInTheSecondWrappedExceptionIsFound(): void
    {
        $envelope = new Envelope(new stdClass());
        $deadlock = new DeadlockException(FakeDriverException::deadlock(), null);
        $conflict = new ConcurrencyConflict(2, TransientFailureKind::Deadlock, 1, $deadlock);
        $lost     = new RuntimeException('wrap', 0, FakeDriverException::lostSavepoint());

        self::assertSame($deadlock, TransientFailureClassifier::findConflict(new HandlerFailedException($envelope, [new RuntimeException('business rule'), $deadlock])));
        self::assertSame($conflict, TransientFailureClassifier::findLockConflict(new HandlerFailedException($envelope, [new RuntimeException('business rule'), $conflict])));
        self::assertNull(TransientFailureClassifier::findLockConflict(new HandlerFailedException($envelope, [new RuntimeException('business rule'), $deadlock])));
        self::assertSame($lost, TransientFailureClassifier::findTransientFailure(new HandlerFailedException($envelope, [new RuntimeException('business rule'), $lost])));
        self::assertSame($deadlock, TransientFailureClassifier::findConflict(new RuntimeException('outer', 0, new HandlerFailedException($envelope, [new RuntimeException('a'), new HandlerFailedException($envelope, [new RuntimeException('b'), $deadlock])]))));
        self::assertNull(TransientFailureClassifier::findConflict(new HandlerFailedException($envelope, [new RuntimeException('business rule'), new UniqueConstraintViolationException(FakeDriverException::uniqueViolation(), null)])));
    }

    /** The walk is bounded and survives cycles and non-throwables: what lies beyond the cap is not found. */
    public function testTheWalkIsBounded(): void
    {
        $deadlock         = new DeadlockException(FakeDriverException::deadlock(), null);
        $wrapper          = new CyclicWrapper();
        $wrapper->wrapped = [$wrapper, null, 'not a throwable', new RuntimeException('a'), $deadlock];
        self::assertSame($deadlock, TransientFailureClassifier::findConflict($wrapper));

        $plain = [];
        for ($i = 0; $i < ThrowableWalk::MAX_NODES; ++$i) {
            $plain[] = new RuntimeException('x' . $i);
        }

        $wrapper->wrapped = [...$plain, $deadlock];
        self::assertNull(TransientFailureClassifier::findConflict($wrapper));
    }
}
