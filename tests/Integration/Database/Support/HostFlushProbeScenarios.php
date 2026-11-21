<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use ErrorException;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\OrphanRemovals;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host\BundleNote;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function in_array;
use function is_array;
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
        $board->cards->add(new Card(30, $board)); // cascade persist would insert the card (audit WD-6); the inverse side writes nothing for the board (1.9.3)
        self::assertSame([Card::class, Counter::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]));

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
        $this->connection->insert('ddrb_profile', ['id' => 1]);
        $this->connection->insert('ddrb_profile', ['id' => 2]);
        $this->connection->insert('ddrb_badge', ['id' => 1, 'profile_id' => 1]);
        $manager = $this->manager();
        $probe   = new HostFlushProbe();
        $cases   = [
            'inverse one-to-one set to null (orphan removal deletes the badge, nothing written for the profile)' => [static function () use ($manager): void {
                $profile = $manager->find(Profile::class, 1);
                self::assertInstanceOf(Profile::class, $profile);
                $profile->badge = null;
            }, [Badge::class]],
            'owning one-to-one changed' => [static function () use ($manager): void {
                // The profiles first: on PHP 8.4 with native lazy objects, ORM 3.8 gives a Profile created as Badge 1's
                // lazy ghost an ["id"] change set (a no-op UPDATE), unrelated to what this case checks.
                $manager->find(Profile::class, 1);
                $profile = $manager->find(Profile::class, 2);
                $badge   = $manager->find(Badge::class, 1);
                self::assertInstanceOf(Badge::class, $badge);
                self::assertInstanceOf(Profile::class, $profile);
                $badge->profile = $profile;
            }, [Badge::class]],
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
            }, [Card::class]], // the inverse side writes nothing for the board (1.9.3)
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
     * Audit XD2-2 (1.9.2): after the host flushed an inverse one-to-one set to null, ORM keeps the deleted Badge in the
     * Profile's loaded state, but no later flush writes anything for either: a bundle flush then is not reported. An
     * owning-side change is still reported.
     */
    private function scenarioTheProbeIgnoresAFlushedInverseOrphan(): void
    {
        $this->connection->insert('ddrb_profile', ['id' => 1]);
        $this->connection->insert('ddrb_profile', ['id' => 2]);
        $this->connection->insert('ddrb_badge', ['id' => 1, 'profile_id' => 1]);
        $this->connection->insert('ddrb_badge', ['id' => 2, 'profile_id' => 2]);
        $manager = $this->manager();
        $probe   = new HostFlushProbe();
        $profile = $manager->find(Profile::class, 1);
        self::assertInstanceOf(Profile::class, $profile);
        $profile->badge = null;
        self::assertSame([Badge::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'the pending orphan removal');

        $manager->flush(); // the host's own flush deletes badge 1
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_badge WHERE id = 1'));
        $probe->reset();
        self::assertSame([], $this->deprecations(static fn () => self::assertSame([], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]))), 'a later bundle flush: nothing reported');

        $manager->find(Profile::class, 2); // before Badge 2 (see scenarioTheProbeMatchesWhatTheFlushWrites)
        $badge = $manager->find(Badge::class, 2);
        self::assertInstanceOf(Badge::class, $badge);
        $badge->profile = $profile;
        self::assertSame([Badge::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'an owning-side change is reported');
    }

    /**
     * Audit TD192-1 (1.9.2): an entity whose class is already reported is still walked, so the orphan its inverse
     * one-to-one leaves and the new entity its collection cascade-persists are reported. Audit TD192-2: a clean
     * collection of another entity assigned to a many-to-many owning side is written for its new owner: reported.
     * Each case is checked against what the flush then writes.
     */
    private function scenarioTheProbeWalksReportedClassesAndSwappedCollections(): void
    {
        $this->connection->insert('ddrb_profile', ['id' => 1]);
        $this->connection->insert('ddrb_badge', ['id' => 1, 'profile_id' => 1]);
        $manager = $this->manager();
        $probe   = new HostFlushProbe();

        $profile = $manager->find(Profile::class, 1);
        self::assertInstanceOf(Profile::class, $profile);
        $profile->badge = null;
        $manager->persist(new Profile(9)); // Profile is reported by the insertion
        self::assertSame([Badge::class, Profile::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'the orphan of an already reported class');
        $manager->flush();
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_badge'), 'the flush deleted the badge');

        $manager->clear();
        $probe->reset();
        $board = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        $manager->persist(new Board(5)); // Board is reported by the insertion
        $board->cards->add(new Card(32, $board)); // cascade persist inserts the card
        self::assertSame([Board::class, Card::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'the cascade of an already reported class');
        $manager->flush();
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card WHERE id = 32'), 'the flush inserted the card');

        $manager->clear();
        $probe->reset();
        $first  = $manager->find(Shelf::class, 1);
        $second = $manager->find(Shelf::class, 5);
        self::assertInstanceOf(Shelf::class, $first);
        self::assertInstanceOf(Shelf::class, $second);
        self::assertCount(1, $first->counters); // initialised, still clean
        $second->counters = $first->counters;
        self::assertSame([Shelf::class], $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE]), 'a clean collection of another owner');
        $manager->flush();
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_shelf_counter WHERE shelf_id = 5 AND counter_id = 1'), 'the flush wrote the join row for the new owner');
    }

    /**
     * 1.9.3 (Beacon census): an inverse-side collection is written only through orphan removal and cascade persist, an
     * owning side through its diffs, and a replaced collection as the flush does. Each case runs on an entity as hydrated
     * (its collections in its loaded state) and on one the host flushed with an onFlush listener that recomputed its change
     * set (ORM then leaves its collections out of the loaded state), and is checked against what the flush writes.
     */
    private function scenarioTheProbeModelsCollectionWrites(): void
    {
        $manager = $this->manager();
        $probe   = new HostFlushProbe();
        $touch   = new TouchOnFlush();
        $manager->getEventManager()->addEventListener([Events::onFlush], $touch);
        $folder = static function (bool $recomputed) use ($manager): Folder {
            $folder = $manager->find(Folder::class, 1);
            self::assertInstanceOf(Folder::class, $folder);
            if ($recomputed) {
                self::touch($manager, $folder, 'leaves');
            }

            return $folder;
        };
        $team = static function (bool $recomputed) use ($manager): Team {
            $team = $manager->find(Team::class, 1);
            self::assertInstanceOf(Team::class, $team);
            if ($recomputed) {
                self::touch($manager, $team, 'members');
            }

            return $team;
        };
        $member = static function (int $id) use ($manager): Member {
            $member = $manager->find(Member::class, $id);
            self::assertInstanceOf(Member::class, $member);

            return $member;
        };

        $cases = [];
        foreach ([false => 'hydrated', true => 'recomputed'] as $recomputed => $state) {
            $recomputed = (bool) $recomputed;
            $cases += [
                $state . ': clean inverse one-to-many, initialised' => [static function () use ($folder, $recomputed): void {
                    self::assertCount(1, $folder($recomputed)->leaves);
                }, []],
                $state . ': uninitialised lazy collection' => [static function () use ($folder, $team, $recomputed): void {
                    $leaves = $folder($recomputed)->leaves;
                    self::assertInstanceOf(PersistentCollection::class, $leaves);
                    self::assertFalse($leaves->isInitialized());
                    $team($recomputed);
                }, []],
                $state . ': new element cascade-persisted' => [static function () use ($folder, $recomputed): void {
                    $owner = $folder($recomputed);
                    $owner->leaves->add(new Leaf(7, $owner, 'new'));
                }, [Leaf::class]],
                $state . ': element removed with orphan removal' => [static function () use ($folder, $recomputed): void {
                    $owner = $folder($recomputed);
                    $leaf  = $owner->leaves->first();
                    self::assertInstanceOf(Leaf::class, $leaf);
                    $owner->leaves->removeElement($leaf);
                }, [Leaf::class]],
                $state . ': owning many-to-many add' => [static function () use ($team, $member, $recomputed): void {
                    $team($recomputed)->members->add($member(2));
                }, [Team::class]],
                $state . ': owning many-to-many remove' => [static function () use ($team, $member, $recomputed): void {
                    $team($recomputed)->members->removeElement($member(1));
                }, [Team::class]],
                $state . ': owning many-to-many add and remove the same element' => [static function () use ($team, $member, $recomputed): void {
                    $owner = $team($recomputed);
                    $owner->members->add($member(2));
                    $owner->members->removeElement($member(2));
                }, []],
                $state . ': inverse many-to-many changed without its owning side' => [static function () use ($team, $member, $recomputed): void {
                    $member(2)->teams->add($team($recomputed));
                    $member(1)->teams->clear();
                }, []],
                $state . ': inverse many-to-many replaced' => [static function () use ($team, $member, $recomputed): void {
                    $team($recomputed);
                    $member(1)->teams = new ArrayCollection();
                }, []],
                $state . ': owning many-to-many replaced by a non-empty collection' => [static function () use ($team, $member, $recomputed): void {
                    $team($recomputed)->members = new ArrayCollection([$member(2)]);
                }, [Team::class]],
                $state . ': owning many-to-many replaced by an empty collection' => [static function () use ($team, $recomputed): void {
                    $team($recomputed)->members = new ArrayCollection();
                }, $recomputed ? [] : [Team::class]], // out of the loaded state: the flush deletes nothing
                $state . ': orphan-removal one-to-many replaced' => [static function () use ($folder, $recomputed): void {
                    $folder($recomputed)->leaves = new ArrayCollection();
                }, $recomputed ? [] : [Folder::class]], // out of the loaded state: the flush deletes nothing
            ];
        }

        try {
            $this->assertTheProbeMatchesTheFlush($manager, $probe, $cases);
        } finally {
            $manager->getEventManager()->removeEventListener([Events::onFlush], $touch);
        }

        $manager->clear();
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_team_member'), 'no case flushed its change');
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_leaf'));
    }

    /**
     * The host's own flush of a label change, through TouchOnFlush: afterwards the entity's collection is no longer in its
     * loaded state, and nothing is pending.
     */
    private static function touch(EntityManagerInterface $manager, Folder|Team $entity, string $collection): void
    {
        $entity->label .= '-';
        $manager->flush();
        self::assertFalse(array_key_exists($collection, $manager->getUnitOfWork()->getOriginalEntityData($entity)), 'ORM left the collection out');
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
        self::assertSame(ErrorException::class, $logger->records[0]['context']['exception_type'], 'only the failure\'s type (audit XB-2)');
        self::assertArrayNotHasKey('exception', $logger->records[0]['context'], 'never its message or trace');
        self::assertSame([], $this->deprecations(static fn (): array => $probe->beforeHostFlush($manager, 'TestBundle', [self::BUNDLE_NAMESPACE])), 'reported once all the same');

        // An inspection that throws (an entity whose class cannot be resolved, as a broken proxy).
        $brokenManager = new BrokenMetadataManager($manager);
        $broken        = new RecordingLogger();
        $probe         = new HostFlushProbe($broken);
        $messages      = $this->deprecations(static fn () => self::assertSame([], $probe->beforeHostFlush($brokenManager, 'TestBundle', [self::BUNDLE_NAMESPACE])));
        self::assertSame([], $messages, 'nothing reported');
        self::assertSame(['warning'], $broken->levels());
        self::assertNull($broken->records[0]['context']['class']);
        self::assertSame(RuntimeException::class, $broken->records[0]['context']['exception_type']);
        self::assertArrayNotHasKey('exception', $broken->records[0]['context']);
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
            foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityDeletions()] as $entities) {
                foreach ($entities as $entity) {
                    $written[$entity::class] = true;
                }
            }

            // An update writes its row only for a field or an owning to-one (BasicEntityPersister::update(): no column, no
            // statement); a collection in its change set is written, or not, by the collection persister below.
            foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
                $metadata = $manager->getClassMetadata($entity::class);
                foreach (array_keys($unitOfWork->getEntityChangeSet($entity)) as $field) {
                    if (!$metadata->hasAssociation($field) || ($metadata->isSingleValuedAssociation($field) && !$metadata->isAssociationInverseSide($field))) {
                        $written[$entity::class] = true;
                    }
                }
            }

            // The collection persisters: an update writes the join rows of an owning side's diffs; a deletion writes an
            // owning side's join rows, or a one-to-many's rows with orphan removal; an inverse side otherwise nothing.
            foreach ([[$unitOfWork->getScheduledCollectionUpdates(), false], [$unitOfWork->getScheduledCollectionDeletions(), true]] as [$collections, $deletion]) {
                foreach ($collections as $collection) {
                    $owner = $collection->getOwner();
                    self::assertNotNull($owner);
                    $metadata = $manager->getClassMetadata($owner::class);
                    $field    = self::fieldName($collection->getMapping());
                    $writes   = $metadata->isAssociationInverseSide($field)
                        ? $deletion && self::removesOrphans($metadata->getAssociationMapping($field)) && !self::isManyToMany($metadata->getAssociationMapping($field))
                        : $deletion || $collection->getInsertDiff() !== [] || $collection->getDeleteDiff() !== [];
                    if ($writes) {
                        $written[$owner::class] = true;
                    }
                }
            }

            foreach (OrphanRemovals::pending($unitOfWork) as $orphan) {
                $written[$orphan::class] = true; // deleted at commit
            }

            foreach ($expected as $class) {
                self::assertTrue(isset($written[$class]), $label . ': Doctrine writes ' . $class . ' too');
            }

            foreach (array_keys($written) as $class) {
                self::assertTrue(str_starts_with($class, self::BUNDLE_NAMESPACE . '\\') || in_array($class, $expected, true), $label . ': ' . $class . ' is written but not reported');
            }
        }
    }

    /** @param array<string, mixed>|object $mapping An association mapping (ORM 2 array, ORM 3 object) */
    private static function fieldName(array|object $mapping): string
    {
        $field = is_array($mapping) ? $mapping['fieldName'] : ($mapping instanceof AssociationMapping ? $mapping->fieldName : '');
        self::assertIsString($field);

        return $field;
    }

    /** @param array<string, mixed>|object $mapping An association mapping (ORM 2 array, ORM 3 object) */
    private static function removesOrphans(array|object $mapping): bool
    {
        return is_array($mapping) ? ($mapping['orphanRemoval'] ?? false) === true : ($mapping instanceof AssociationMapping && $mapping->orphanRemoval);
    }

    /** @param array<string, mixed>|object $mapping An association mapping (ORM 2 array, ORM 3 object) */
    private static function isManyToMany(array|object $mapping): bool
    {
        return is_array($mapping) ? ($mapping['type'] ?? null) === ClassMetadata::MANY_TO_MANY : ($mapping instanceof AssociationMapping && $mapping->isManyToMany());
    }
}
