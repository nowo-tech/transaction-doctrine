<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use ErrorException;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host\BundleNote;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use RuntimeException;

use function array_keys;
use function in_array;
use function method_exists;
use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;

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
        $board->cards->add(new Card(30, $board)); // a dirty collection; cascade persist would insert the card (audit WD-6)
        self::assertSame([Board::class, Card::class, Counter::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]));

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

    /**
     * Audits WD-4 / WD-6: what the host's flush writes decides. Each case starts from a cleared manager and is compared
     * with what Doctrine's own change-set computation would write (computed on the side, after the probe).
     */
    private function scenarioTheProbeMatchesWhatTheFlushWrites(): void
    {
        $this->connection->insert('ddrb_card', ['id' => 19, 'board_id' => 1]);
        $manager = $this->manager();
        $probe   = new HostFlushProbe();
        $cases   = [
            'many-to-many clear (join rows deleted)' => [static function () use ($manager): void {
                $shelf = $manager->find(Shelf::class, 1);
                self::assertInstanceOf(Shelf::class, $shelf);
                $shelf->counters->clear();
            }, [Shelf::class]],
            'orphan removal deletes the card' => [static function () use ($manager): void {
                $board = $manager->find(Board::class, 1);
                self::assertInstanceOf(Board::class, $board);
                $card = $board->cards->first();
                self::assertInstanceOf(Card::class, $card);
                $board->cards->removeElement($card);
            }, [Board::class, Card::class]],
            'host subclass of a bundle-owned root' => [static function () use ($manager): void {
                $item = $manager->find(HostItem::class, 2);
                self::assertInstanceOf(HostItem::class, $item);
                $item->label = 'changed';
            }, [HostItem::class]],
            'the bundle root itself' => [static function () use ($manager): void {
                $item = $manager->find(Host\BundleItem::class, 1);
                self::assertInstanceOf(Host\BundleItem::class, $item);
                $item->label = 'changed';
            }, []],
            'read-only entity changed' => [static function () use ($manager): void {
                $frozen = $manager->find(Frozen::class, 1);
                self::assertInstanceOf(Frozen::class, $frozen);
                $frozen->label = 'changed';
            }, []],
            'DEFERRED_EXPLICIT changed, not persisted' => [static function () use ($manager): void {
                $explicit = $manager->find(Explicit::class, 1);
                self::assertInstanceOf(Explicit::class, $explicit);
                $explicit->label = 'changed';
            }, []],
            'DEFERRED_EXPLICIT changed and persisted' => [static function () use ($manager): void {
                $explicit = $manager->find(Explicit::class, 1);
                self::assertInstanceOf(Explicit::class, $explicit);
                $explicit->label = 'changed';
                $manager->persist($explicit);
            }, [Explicit::class]],
            'an equal object replaces the loaded one' => [static function () use ($manager): void {
                $stamp = $manager->find(Stamp::class, 1);
                self::assertInstanceOf(Stamp::class, $stamp);
                $stamp->at = new DateTimeImmutable('2026-01-01 10:00:00');
            }, self::ormComparesObjectValues() ? [] : [Stamp::class]],
        ];

        $this->assertTheProbeMatchesTheFlush($manager, $probe, $cases);
        $manager->clear();
        self::assertSame(0, (int) $this->freshValue("SELECT COUNT(*) FROM ddrb_owner WHERE label = 'changed'"), 'nothing flushed');
    }

    /**
     * Audit XA-1: a backed-enum field is loaded as its case(s) but read as its backing value(s); compared the way
     * UnitOfWork::computeChangeSet() does (ORM 2.20, 3.7, 3.8), an untouched entity is not pending work.
     */
    private function scenarioTheProbeComparesEnumsAsTheFlushDoes(): void
    {
        $manager = $this->manager();
        $probe   = new HostFlushProbe();
        $ticket  = static function () use ($manager): Ticket {
            $ticket = $manager->find(Ticket::class, 1);
            self::assertInstanceOf(Ticket::class, $ticket);

            return $ticket;
        };
        $cases = [
            'loaded and untouched' => [static function () use ($manager): void {
                self::assertCount(2, $manager->getRepository(Ticket::class)->findAll());
                self::assertInstanceOf(EnumKeyed::class, $manager->find(EnumKeyed::class, Kind::Alpha)); // an enum identifier
            }, []],
            'enum changed' => [static function () use ($ticket): void {
                $ticket()->status = Kind::Beta;
            }, [Ticket::class]],
            'enum set to the same case' => [static function () use ($ticket): void {
                $ticket()->status = Kind::Alpha;
            }, []],
            'array of enums untouched, other fields too' => [static function () use ($ticket): void {
                self::assertSame([Kind::Alpha, Kind::Beta], $ticket()->kinds);
            }, []],
            'array of enums replaced by an equal one' => [static function () use ($ticket): void {
                $ticket()->kinds = [Kind::Alpha, Kind::Beta];
            }, []],
            'array of enums changed' => [static function () use ($ticket): void {
                $ticket()->kinds = [Kind::Beta];
            }, [Ticket::class]],
            'nullable enum set to null' => [static function () use ($ticket): void {
                $ticket()->priority = null;
            }, [Ticket::class]],
            'nullable enum loaded null, set to a case' => [static function () use ($manager): void {
                $ticket = $manager->find(Ticket::class, 2);
                self::assertInstanceOf(Ticket::class, $ticket);
                self::assertNull($ticket->priority);
                $ticket->priority = Kind::Alpha;
            }, [Ticket::class]],
        ];

        $this->assertTheProbeMatchesTheFlush($manager, $probe, $cases);
        $manager->clear();
        self::assertSame(1, (int) $this->freshValue("SELECT COUNT(*) FROM ddrb_ticket WHERE status = 'alpha' AND priority = 'beta' AND kinds = 'alpha,beta'"), 'nothing flushed');
    }

    /**
     * Audit XA-3: the probe never breaks the caller's write. A host error handler that ignores @ and throws on the
     * deprecation, an inspection that throws, and a logger that throws are all caught (logged when a logger is wired).
     */
    private function scenarioTheProbeNeverBreaksTheWrite(): void
    {
        $manager = $this->manager();
        $counter = $manager->find(Counter::class, 1);
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Counter::class, $counter);
        self::assertInstanceOf(Board::class, $board);
        ++$counter->value;
        $manager->persist(new Card(40, $board));

        $logger = new RecordingLogger();
        $probe  = new HostFlushProbe($logger);
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test helper, restored in finally)
        set_error_handler(static function (int $level, string $message): never {
            throw new ErrorException($message, 0, $level); // ignores @, as some hosts do
        });

        try {
            self::assertSame([Card::class, Counter::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]));
            self::assertSame([Card::class, Counter::class], (new HostFlushProbe())->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'no logger: swallowed');
            self::assertSame([Card::class, Counter::class], (new HostFlushProbe(new ThrowingLogger()))->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'a throwing logger: swallowed');
        } finally {
            restore_error_handler();
        }

        self::assertSame(['warning', 'warning'], $logger->levels(), 'one warning per class, the next class still reported');
        self::assertSame(Card::class, $logger->records[0]['context']['class']);
        self::assertSame('TestBundle', $logger->records[0]['context']['bundle']);
        self::assertInstanceOf(ErrorException::class, $logger->records[0]['context']['exception']);
        self::assertSame([], $this->deprecations(static fn (): array => $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE])), 'reported once all the same');

        // An inspection that throws (an entity whose class cannot be resolved, as a broken proxy).
        $brokenManager = new BrokenMetadataManager($manager);
        $broken        = new RecordingLogger();
        $probe         = new HostFlushProbe($broken);
        $messages      = $this->deprecations(static fn () => self::assertSame([], $probe->beforeHostFlush($brokenManager, 'TestBundle', [self::BUNDLE_NAMESPACE])));
        self::assertSame([], $messages, 'nothing reported');
        self::assertSame(['warning'], $broken->levels());
        self::assertNull($broken->records[0]['context']['class']);
        self::assertInstanceOf(RuntimeException::class, $broken->records[0]['context']['exception']);
        self::assertSame([], (new HostFlushProbe())->beforeHostFlush($brokenManager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'no logger: swallowed');
        self::assertSame([], (new HostFlushProbe(new ThrowingLogger()))->beforeHostFlush($brokenManager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'a throwing logger: swallowed');
        self::assertSame([Card::class, Counter::class], (new HostFlushProbe())->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'the real manager still inspects');
        self::assertTrue($manager->isOpen());
    }

    /** Whether the installed ORM compares a replaced object field by its database value (recent ORM 3; not ORM 2). */
    private static function ormComparesObjectValues(): bool
    {
        return method_exists(UnitOfWork::class, 'isSameConvertedDatabaseValue'); // @phpstan-ignore function.alreadyNarrowedType (absent from older ORMs)
    }

    /**
     * Runs each case on a cleared manager and a reset probe: the probe reports exactly the expected classes, and
     * Doctrine's own change-set computation (on the side, after the probe) writes exactly those (plus the bundle's).
     *
     * @param array<string, array{callable(): void, list<class-string>}> $cases
     */
    private function assertTheProbeMatchesTheFlush(EntityManagerInterface $manager, HostFlushProbe $probe, array $cases): void
    {
        foreach ($cases as $label => [$mutate, $expected]) {
            $manager->clear();
            $probe->reset();
            $mutate();
            self::assertSame($expected, $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]), $label);

            $unitOfWork = $manager->getUnitOfWork();
            $unitOfWork->computeChangeSets();
            $written = [];
            foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $entities) {
                foreach ($entities as $entity) {
                    $written[$entity::class] = true;
                }
            }

            foreach ([$unitOfWork->getScheduledCollectionUpdates(), $unitOfWork->getScheduledCollectionDeletions()] as $collections) {
                foreach ($collections as $collection) {
                    $owner = $collection->getOwner();
                    self::assertNotNull($owner);
                    $written[$owner::class] = true;
                }
            }

            foreach ($expected as $class) {
                self::assertTrue(isset($written[$class]) || $class === Card::class, $label . ': Doctrine writes ' . $class . ' too (orphan removals are deleted at commit)');
            }

            foreach (array_keys($written) as $class) {
                self::assertTrue(str_starts_with($class, self::BUNDLE_NAMESPACE . '\\') || in_array($class, $expected, true), $label . ': ' . $class . ' is written but not reported');
            }
        }
    }
}
