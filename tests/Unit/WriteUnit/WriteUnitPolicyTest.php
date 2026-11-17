<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit;

use InvalidArgumentException;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WriteUnitPolicyTest extends TestCase
{
    public function testDefaultsReplayDeadlocksAndLockWaitTimeoutsThreeTimes(): void
    {
        $policy = new WriteUnitPolicy();

        self::assertSame(3, $policy->maxAttempts);
        self::assertSame(1, $policy->retryAfterSeconds);
        self::assertSame([TransientFailureKind::Deadlock, TransientFailureKind::LockWaitTimeout], $policy->replayKinds);
        self::assertTrue($policy->replays(TransientFailureKind::Deadlock));
        self::assertTrue($policy->replays(TransientFailureKind::LockWaitTimeout));
        self::assertFalse($policy->replays(TransientFailureKind::SerializationFailure));
        self::assertFalse($policy->replays(TransientFailureKind::Retryable));
    }

    public function testKindsAcceptCasesAndValues(): void
    {
        $policy = new WriteUnitPolicy(replayKinds: ['serialization_failure', TransientFailureKind::Retryable]);

        self::assertSame([TransientFailureKind::SerializationFailure, TransientFailureKind::Retryable], $policy->replayKinds);
    }

    public function testAnUnknownKindIsRefused(): void
    {
        $this->expectException(ValueError::class);

        new WriteUnitPolicy(replayKinds: ['nope']);
    }

    public function testPauseBoundsGrowExponentiallyUpToTheCap(): void
    {
        $policy = new WriteUnitPolicy();

        self::assertSame([20, 100], $policy->pauseBounds(1));
        self::assertSame([20, 200], $policy->pauseBounds(2));
        self::assertSame([20, 200], $policy->pauseBounds(9));
        self::assertSame([20, 200], $policy->pauseBounds(1000));
        self::assertSame([20, 100], $policy->pauseBounds(0));
    }

    public function testTheFloorWinsOverASmallerCeiling(): void
    {
        self::assertSame([50, 50], (new WriteUnitPolicy(3, 50, 10, 20))->pauseBounds(1));
    }

    /**
     * @return iterable<string, array{int, int, int, int, int|null}>
     */
    public static function invalidBounds(): iterable
    {
        yield 'no attempt' => [0, 20, 100, 200, 1];
        yield 'negative floor' => [3, -1, 100, 200, 1];
        yield 'negative base' => [3, 20, -1, 200, 1];
        yield 'negative cap' => [3, 20, 100, -1, 1];
        yield 'negative retry after' => [3, 20, 100, 200, -1];
    }

    #[DataProvider('invalidBounds')]
    public function testInvalidBoundsAreRefused(int $attempts, int $floor, int $base, int $cap, ?int $retryAfter): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WriteUnitPolicy($attempts, $floor, $base, $cap, null, $retryAfter);
    }

    public function testNoRetryAfterHintIsAllowed(): void
    {
        self::assertNull((new WriteUnitPolicy(retryAfterSeconds: null))->retryAfterSeconds);
    }
}
