<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use LogicException;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\Tests\Unit\Support\Journal;
use Nowo\TransactionDoctrine\Tests\Unit\Support\RecordingRegistry;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitRetryEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

use function memory_get_usage;

/**
 * ResetBetweenAttemptsInterface and WriteUnitRetryEvent: called after the managers are cleared and before each
 * replay, in order, never before the first attempt nor after the last failure; a failure abandons the replay.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ReplayResetTest extends TestCase
{
    public function testResettersRunInOrderAfterTheClearThenTheEventBeforeTheReplay(): void
    {
        $journal    = new Journal();
        $dispatcher = new EventDispatcher();
        $events     = new EventLog();
        $dispatcher->addListener(WriteUnitRetryEvent::class, static function (WriteUnitRetryEvent $event) use ($journal, $events): void {
            $journal->add('event');
            $events->events[] = $event;
        });
        $unit     = $this->unit($journal, 3, [$journal->resetter('first'), $journal->resetter('second')], $dispatcher);
        $failures = [];

        $result = $unit->run(static function (int $attempt) use ($journal, &$failures): int {
            $journal->add('attempt ' . $attempt);
            if ($attempt < 3) {
                $failures[] = $failure = FakeDriverException::deadlock();

                throw $failure;
            }

            return $attempt;
        }, 'board_move');

        self::assertSame(3, $result);
        self::assertSame([
            'attempt 1',
            'clear', 'first', 'second', 'event', 'attempt 2',
            'clear', 'first', 'second', 'event', 'attempt 3',
        ], $journal->entries);
        self::assertCount(2, $events->events);
        [$second, $third] = $events->events;
        self::assertSame(2, $second->attempt);
        self::assertSame(3, $third->attempt);
        self::assertSame($failures[0] ?? null, $second->failure);
        self::assertSame($failures[1] ?? null, $third->failure);
        self::assertSame(TransientFailureKind::Deadlock, $second->kind);
        self::assertSame('board_move', $second->unit);
    }

    public function testNothingRunsWhenTheFirstAttemptSucceeds(): void
    {
        $journal = new Journal();
        $unit    = $this->unit($journal, 3, [$journal->resetter('reset')], $this->recordingDispatcher($journal));

        self::assertSame(1, $unit->run(static fn (int $attempt): int => $attempt));
        self::assertSame([], $journal->entries);
    }

    public function testNothingRunsAfterTheLastFailure(): void
    {
        $journal = new Journal();
        $unit    = $this->unit($journal, 2, [$journal->resetter('reset')], $this->recordingDispatcher($journal));

        $caught = $this->conflictOf($unit, static function (int $attempt) use ($journal): never {
            $journal->add('attempt ' . $attempt);

            throw FakeDriverException::deadlock();
        });

        self::assertSame(2, $caught->attempts);
        self::assertNull($caught->resetFailure);
        self::assertSame(['attempt 1', 'clear', 'reset', 'event', 'attempt 2', 'clear'], $journal->entries);
    }

    public function testNothingRunsForAFailureThatIsNotReplayed(): void
    {
        $journal = new Journal();
        $unit    = $this->unit($journal, 3, [$journal->resetter('reset')], $this->recordingDispatcher($journal));

        $this->expectException(LogicException::class);

        try {
            $unit->run(static function (): never {
                throw new LogicException('bug');
            });
        } finally {
            self::assertSame([], $journal->entries);
        }
    }

    public function testAFailingResetterAbandonsTheReplayWithTheConflict(): void
    {
        $journal = new Journal();
        $broken  = new RuntimeException('memo broken');
        $failing = new class($broken) implements ResetBetweenAttemptsInterface {
            public function __construct(private readonly Throwable $failure)
            {
            }

            public function resetBetweenAttempts(): void
            {
                throw $this->failure;
            }
        };
        $logger = new RecordingLogger();
        $unit   = $this->unit($journal, 3, [$failing, $journal->resetter('never')], $this->recordingDispatcher($journal), $logger);
        $first  = FakeDriverException::deadlock();

        $caught = $this->conflictOf($unit, static function (int $attempt) use ($journal, $first): never {
            $journal->add('attempt ' . $attempt);

            throw $first;
        });

        self::assertSame(1, $caught->attempts);
        self::assertSame(TransientFailureKind::Deadlock, $caught->kind);
        self::assertSame($first, $caught->getPrevious());
        self::assertSame($broken, $caught->resetFailure);
        self::assertSame(1, $caught->retryAfterSeconds());
        self::assertFalse($caught->committed);
        self::assertSame(['attempt 1', 'clear'], $journal->entries);
        self::assertCount(2, $logger->records);
        self::assertSame('Write unit not replayed: resetting request state between attempts failed.', $logger->records[1]['message']);
        self::assertSame('warning', $logger->records[1]['level']);
        self::assertSame(RuntimeException::class, $logger->records[1]['context']['exception_class']);
        self::assertSame(1, $logger->records[1]['context']['attempts']);
    }

    public function testAFailingRetryListenerAbandonsTheReplayToo(): void
    {
        $journal    = new Journal();
        $dispatcher = new EventDispatcher();
        $broken     = new RuntimeException('listener broken');
        $dispatcher->addListener(WriteUnitRetryEvent::class, static function () use ($broken): never {
            throw $broken;
        });
        $unit = $this->unit($journal, 3, [$journal->resetter('reset')], $dispatcher);

        $caught = $this->conflictOf($unit, static function (): never {
            throw FakeDriverException::deadlock();
        });

        self::assertSame($broken, $caught->resetFailure);
        self::assertSame(1, $caught->attempts);
        self::assertSame(['clear', 'reset'], $journal->entries);
    }

    /** FrankenPHP worker, kernel reset off: thousands of replays keep nothing on the unit. */
    public function testManyReplaysKeepNoState(): void
    {
        $journal = new Journal();
        $unit    = new WriteUnit(new RecordingRegistry(), new WriteUnitPolicy(2, 0, 0, 0), null, new MockClock(), null, null, null, [$journal->resetter('reset')], new EventDispatcher());
        $work    = static function (int $attempt): int {
            if ($attempt === 1) {
                throw FakeDriverException::deadlock();
            }

            return $attempt;
        };
        $unit->run($work, 'warm-up');
        $journal->entries = [];
        $before           = memory_get_usage();

        for ($i = 0; $i < 2000; ++$i) {
            $unit->run($work, 'unit');
            $journal->entries = [];
        }

        self::assertLessThan(256 * 1024, memory_get_usage() - $before);
    }

    /**
     * @param callable(int, ?Throwable): void $work
     */
    private function conflictOf(WriteUnit $unit, callable $work): ConcurrencyConflict
    {
        $caught = null;

        try {
            $unit->run($work);
        } catch (ConcurrencyConflict $conflict) {
            $caught = $conflict;
        }

        self::assertInstanceOf(ConcurrencyConflict::class, $caught);

        return $caught;
    }

    /**
     * @param iterable<ResetBetweenAttemptsInterface> $resetters
     */
    private function unit(Journal $journal, int $attempts, iterable $resetters, EventDispatcher $dispatcher, ?RecordingLogger $logger = null): WriteUnit
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('getUnitOfWork')->willReturn($this->createConfiguredStub(UnitOfWork::class, [
            'getScheduledEntityInsertions'    => [],
            'getScheduledEntityUpdates'       => [],
            'getScheduledEntityDeletions'     => [],
            'getScheduledCollectionDeletions' => [],
            'getScheduledCollectionUpdates'   => [],
        ]));
        $manager->method('clear')->willReturnCallback(static function () use ($journal): void {
            $journal->add('clear');
        });
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getTransactionNestingLevel')->willReturn(0);

        return new WriteUnit(new RecordingRegistry(['default' => $connection], ['default' => $manager]), new WriteUnitPolicy($attempts, 0, 0, 0), null, new MockClock(), $logger, null, null, $resetters, $dispatcher);
    }

    private function recordingDispatcher(Journal $journal): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WriteUnitRetryEvent::class, static function () use ($journal): void {
            $journal->add('event');
        });

        return $dispatcher;
    }
}

/**
 * @internal
 */
final class EventLog
{
    /** @var list<WriteUnitRetryEvent> */
    public array $events = [];
}
