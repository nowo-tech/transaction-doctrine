<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\Tests\Unit\Support\RecordingRegistry;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Throwable;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WriteUnitTest extends TestCase
{
    public function testTheFirstSuccessfulAttemptReturnsWithoutRepair(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('clear');
        $unit = new WriteUnit(new RecordingRegistry(['default' => $this->idleConnection()], ['default' => $manager]), new WriteUnitPolicy(), null, new MockClock());

        self::assertSame('done', $unit->run(static fn (int $attempt, ?Throwable $previous): string => $attempt === 1 && !$previous instanceof Throwable ? 'done' : 'wrong'));
    }

    public function testADeadlockIsReplayedWithAClearedManagerAndThePreviousFailure(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->expects($this->once())->method('clear');
        $clock    = new MockClock('2026-11-17 10:00:00');
        $start    = (float) $clock->now()->format('U.u');
        $logger   = new RecordingLogger();
        $unit     = new WriteUnit(new RecordingRegistry(['default' => $this->idleConnection()], ['default' => $manager]), new WriteUnitPolicy(), null, $clock, $logger);
        $deadlock = new DeadlockException(FakeDriverException::deadlock(), null);
        $seen     = [];

        $result = $unit->run(static function (int $attempt, ?Throwable $previous) use ($deadlock, &$seen): string {
            $seen[] = [$attempt, $previous];
            if ($attempt === 1) {
                throw $deadlock;
            }

            return 'saved ' . $attempt;
        });

        self::assertSame('saved 2', $result);
        self::assertSame([[1, null], [2, $deadlock]], $seen);
        $paused = (float) $clock->now()->format('U.u') - $start;
        self::assertGreaterThanOrEqual(0.020, $paused);
        self::assertLessThanOrEqual(0.100, $paused);
        self::assertSame('Write unit met a database lock conflict; replaying it.', $logger->records[0]['message']);
        self::assertSame('deadlock', $logger->records[0]['context']['kind']);
    }

    public function testAClosedManagerIsResetBetweenAttempts(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(false);
        $manager->expects($this->never())->method('clear');
        $registry = new RecordingRegistry(['default' => $this->idleConnection()], ['default' => $manager], ['default' => $this->createMock(EntityManagerInterface::class)]);
        $unit     = new WriteUnit($registry, new WriteUnitPolicy(), null, new MockClock());

        $unit->run(static function (int $attempt): void {
            if ($attempt === 1) {
                throw TransientStorageFailure::fromPrevious(new DeadlockException(FakeDriverException::deadlock(), null));
            }
        });

        self::assertSame(['default'], $registry->resets);
    }

    public function testTheLastConflictBecomesConcurrencyConflict(): void
    {
        $logger = new RecordingLogger();
        $unit   = new WriteUnit(new RecordingRegistry(['default' => $this->idleConnection()]), new WriteUnitPolicy(3, 0, 0, 0, null, 7), null, new MockClock(), $logger);
        $calls  = 0;

        try {
            $unit->run(static function () use (&$calls): never {
                ++$calls;

                throw FakeDriverException::lockWaitTimeout();
            });
        } catch (ConcurrencyConflict $conflict) {
            self::assertSame(3, $calls);
            self::assertSame(3, $conflict->attempts);
            self::assertSame(TransientFailureKind::LockWaitTimeout, $conflict->kind);
            self::assertSame(7, $conflict->retryAfterSeconds());
            self::assertSame(ConcurrencyConflict::MESSAGE, $conflict->getMessage());
            self::assertInstanceOf(FakeDriverException::class, $conflict->getPrevious());
        }

        self::assertSame('Write unit gave up after repeated database lock conflicts.', $logger->records[2]['message']);
        self::assertSame(3, $logger->records[2]['context']['attempts']);
    }

    public function testAnotherFailureIsRethrownAtOnceWithoutRepair(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects($this->never())->method('clear');
        $unit  = new WriteUnit(new RecordingRegistry(['default' => $this->idleConnection()], ['default' => $manager]), new WriteUnitPolicy(), null, new MockClock());
        $calls = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refused');

        try {
            $unit->run(static function () use (&$calls): never {
                ++$calls;

                throw new RuntimeException('refused');
            });
        } finally {
            self::assertSame(1, $calls);
        }
    }

    public function testAKindOutsideThePolicyIsNotReplayed(): void
    {
        $unit = new WriteUnit(new RecordingRegistry(['default' => $this->idleConnection()]), new WriteUnitPolicy(), null, new MockClock());

        $this->expectException(FakeDriverException::class);

        $unit->run(static function (): never {
            throw FakeDriverException::serializationFailure();
        });
    }

    public function testAnOptedInKindIsReplayed(): void
    {
        $unit = new WriteUnit(new RecordingRegistry(['default' => $this->idleConnection()]), new WriteUnitPolicy(2, 0, 0, 0, ['serialization_failure']), null, new MockClock());

        self::assertSame(2, $unit->run(static function (int $attempt): int {
            if ($attempt === 1) {
                throw FakeDriverException::serializationFailure();
            }

            return $attempt;
        }));
    }

    public function testAnInnerConcurrencyConflictIsNotReplayedAgain(): void
    {
        $unit     = new WriteUnit(new RecordingRegistry(['default' => $this->idleConnection()]), new WriteUnitPolicy(), null, new MockClock());
        $calls    = 0;
        $conflict = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, FakeDriverException::deadlock());

        try {
            $unit->run(static function () use (&$calls, $conflict): never {
                ++$calls;

                throw $conflict;
            });
        } catch (ConcurrencyConflict $caught) {
            self::assertSame($conflict, $caught);
            self::assertSame(1, $calls);
        }
    }

    public function testInsideATransactionTheWorkRunsOnceAndTheFailurePropagates(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $unit     = new WriteUnit(new RecordingRegistry(['other' => $this->idleConnection(), 'default' => $connection]), new WriteUnitPolicy(), null, new MockClock());
        $deadlock = FakeDriverException::deadlock();
        $calls    = 0;

        try {
            $unit->run(static function (int $attempt, ?Throwable $previous) use (&$calls, $deadlock): never {
                ++$calls;
                self::assertSame(1, $attempt);
                self::assertNull($previous);

                throw $deadlock;
            });
        } catch (FakeDriverException $caught) {
            self::assertSame($deadlock, $caught);
            self::assertSame(1, $calls);
        }
    }

    public function testAConflictInsideAMessengerHandlerFailureIsReplayed(): void
    {
        $unit = new WriteUnit(new RecordingRegistry(), new WriteUnitPolicy(), null, new MockClock());

        self::assertSame(2, $unit->run(static function (int $attempt): int {
            if ($attempt === 1) {
                throw new RuntimeException('wrapper', 0, new HandlerFailedException(new Envelope(new stdClass()), [new RuntimeException('first'), FakeDriverException::deadlock()]));
            }

            return $attempt;
        }));
    }

    public function testConflictKindReadsTheChainAndWrappedExceptions(): void
    {
        $unit = new WriteUnit(new RecordingRegistry());

        self::assertSame(TransientFailureKind::Deadlock, $unit->conflictKind(new RuntimeException('x', 0, FakeDriverException::deadlock())));
        self::assertNull($unit->conflictKind(new RuntimeException('x', 0, new HandlerFailedException(new Envelope(new stdClass()), [new RuntimeException('a')]))));
        self::assertNull($unit->conflictKind(new RuntimeException('plain')));
    }

    public function testPausesStayWithinTheJitterBounds(): void
    {
        $unit = new WriteUnit(new RecordingRegistry(), new WriteUnitPolicy(), null, null, null, new Randomizer(new Mt19937(42)));

        for ($i = 0; $i < 200; ++$i) {
            $first = $unit->pauseMilliseconds(1);
            self::assertGreaterThanOrEqual(20, $first);
            self::assertLessThanOrEqual(100, $first);
            $later = $unit->pauseMilliseconds(5);
            self::assertGreaterThanOrEqual(20, $later);
            self::assertLessThanOrEqual(200, $later);
        }
    }

    public function testWithoutAClockThePauseSleeps(): void
    {
        $unit = new WriteUnit(new RecordingRegistry(), new WriteUnitPolicy(2, 1, 1, 1));

        self::assertSame(2, $unit->run(static function (int $attempt): int {
            if ($attempt === 1) {
                throw FakeDriverException::deadlock();
            }

            return $attempt;
        }));
    }

    public function testManyRunsKeepNoState(): void
    {
        $unit = new WriteUnit(new RecordingRegistry(), new WriteUnitPolicy(2, 0, 0, 0), null, new MockClock());
        $unit->run(static fn (): int => 1);
        $before = memory_get_usage();

        for ($i = 0; $i < 2000; ++$i) {
            $unit->run(static function (int $attempt): int {
                if ($attempt === 1) {
                    throw FakeDriverException::deadlock();
                }

                return $attempt;
            });
        }

        self::assertLessThan(256 * 1024, memory_get_usage() - $before);
    }

    private function idleConnection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getTransactionNestingLevel')->willReturn(0);

        return $connection;
    }
}
