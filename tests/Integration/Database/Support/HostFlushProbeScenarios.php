<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host\BundleNote;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * 1.9: the host-flush probe on a real ORM. The "bundle" owns Support\Host; every other fixture class plays the host's.
 * Shared by the real-database suite and the unit suite (SQLite file).
 *
 * @phpstan-require-extends DatabaseTestCase
 */
trait HostFlushProbeScenarios
{
    private const BUNDLE_NAMESPACE = 'Nowo\\TransactionDoctrine\\Tests\\Integration\\Database\\Support\\Host';

    /**
     * Runs the callable and returns the E_USER_DEPRECATED messages it raised.
     *
     * @param callable(): mixed $call
     *
     * @return list<string>
     */
    private function deprecations(callable $call): array
    {
        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test helper, restored in finally)
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            if ($level === E_USER_DEPRECATED) {
                $messages[] = $message;
            }

            return true;
        });

        try {
            $call();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }

    /**
     * The host changed a counter, persisted one and removed another; the bundle's own note changed too. Only the host's
     * classes are reported, once per unit of work; reset() starts a new one. Nothing is flushed or cleared.
     */
    private function scenarioTheProbeReportsTheHostsPendingChangesOnce(): void
    {
        $manager = $this->manager();
        $probe   = new HostFlushProbe();
        $counter = $manager->find(Counter::class, 1);
        $note    = $manager->find(BundleNote::class, 1);
        self::assertInstanceOf(Counter::class, $counter);
        self::assertInstanceOf(BundleNote::class, $note);
        $manager->getReference(Counter::class, 2); // an uninitialised proxy: never looked at

        $none = $this->deprecations(static fn () => self::assertSame([], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE])));
        self::assertSame([], $none);

        $note->counterId = 2; // the bundle's own change: never reported
        self::assertSame([], $probe->beforeHostFlush($manager, 'TestBundle', ['\\' . self::BUNDLE_NAMESPACE . '\\']));

        ++$counter->value;
        $first = $this->deprecations(static fn () => self::assertSame([Counter::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE])));
        self::assertSame(['Your pending changes to ' . Counter::class . ' were flushed by TestBundle; 2.0 no longer does.'], $first);
        self::assertSame([], $this->deprecations(static fn (): array => $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE])), 'once per unit of work');
        self::assertCount(1, $this->deprecations(static fn (): array => $probe->beforeHostFlush($manager, 'OtherBundle', [self::BUNDLE_NAMESPACE])), 'per bundle');

        $probe->reset();
        self::assertCount(1, $this->deprecations(static fn (): array => $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE])), 'a new unit of work');

        $manager->persist(new Counter(20));
        $board = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        $board->cards->add(new Card(30, $board)); // a dirty collection (the card is never persisted)
        self::assertSame([Board::class, Counter::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]));

        self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), 'nothing flushed');
        self::assertTrue($manager->contains($counter), 'nothing cleared');
        self::assertTrue($manager->isOpen());
    }

    /** A removal and a changed to-one association are pending work too. */
    private function scenarioTheProbeSeesRemovalsAndToOneChanges(): void
    {
        $this->connection->insert('ddrb_board', ['id' => 2]);
        $this->connection->insert('ddrb_card', ['id' => 9, 'board_id' => 1]);
        $manager = $this->manager();
        $probe   = new HostFlushProbe();
        $card    = $manager->find(Card::class, 9);
        $other   = $manager->find(Board::class, 2);
        $removed = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Card::class, $card);
        self::assertInstanceOf(Board::class, $other);
        self::assertInstanceOf(Counter::class, $removed);

        $card->board = $other;
        $manager->remove($removed);
        self::assertSame([Card::class, Counter::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]));
    }
}
