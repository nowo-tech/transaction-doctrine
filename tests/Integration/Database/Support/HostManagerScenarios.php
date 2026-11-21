<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Events;
use DomainException;
use LogicException;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use RuntimeException;
use Throwable;

use function array_column;
use function bin2hex;
use function random_bytes;

/**
 * What forHostEntityManager() leaves in the host's open manager after a failed run (audit TA3-1 to TA3-3). Shared by
 * the real-database suite (MySQL, MariaDB, PostgreSQL) and the unit suite (SQLite file), on ORM 2 and ORM 3.
 *
 * @phpstan-require-extends DatabaseTestCase
 */
trait HostManagerScenarios
{
    private const PENDING_NOTICE = 'Entities the host managed before the failed run still have pending changes (the host\'s own or the run\'s); the host decides whether to refresh() or clear().';

    /**
     * TA3-1: the run's new Note and a run-loaded Note both cascade detach into the host's Counter. The Counter stays
     * managed, so the host's edit after the failure is flushed; the run's entities are forgotten.
     */
    private function scenarioADetachNeverCascadesIntoTheHostsEntity(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $host);
        $note = new Note(70, $host);

        $failure = $this->hostFailure(function () use ($manager, $note): void {
            $manager->persist($note);
            self::assertInstanceOf(Note::class, $manager->find(Note::class, 1));   // its counter is the host's
            $this->connection->insert('ddrb_counter', ['id' => 2, 'counter_value' => 0]);   // duplicate key
        });

        self::assertInstanceOf(Throwable::class, $failure);
        self::assertSame($manager, $this->manager(), 'Not reset.');
        self::assertSame(0, $this->registry->resets);
        self::assertTrue($manager->contains($host), 'The host entity was not detached by cascade.');
        self::assertFalse($manager->contains($note));
        self::assertFalse($manager->getUnitOfWork()->tryGetById(1, Note::class));

        $host->value = 11;   // the host goes on with its own entity
        $manager->flush();
        self::assertSame(11, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), 'The host\'s edit is flushed.');
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_note WHERE id = 70'));
    }

    /** TA3-2: a child the run added to the host's initialized collection is removed from it; the collection is clean. */
    private function scenarioARolledBackChildLeavesTheHostsCollection(): void
    {
        $this->connection->insert('ddrb_card', ['id' => 1, 'board_id' => 1]);
        $manager = $this->manager();
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        self::assertCount(1, $board->cards);   // initialized, one loaded card
        $logger = new RecordingLogger();

        $this->hostFailure(static function () use ($manager, $board): never {
            $card = new Card(5, $board);
            $board->cards->add($card);
            $manager->persist($card);

            throw new DomainException('refused');
        }, $logger);

        self::assertCount(1, $board->cards);
        self::assertNotContains(self::PENDING_NOTICE, array_column($logger->records, 'message'), 'Nothing of the host\'s is pending.');
        $manager->flush();
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card'), 'The host\'s flush does not insert the rolled-back child.');
    }

    /** TA3-2: the same with a collection the host never loaded: add() keeps the child without initializing it. */
    private function scenarioARolledBackChildLeavesAnUninitializedCollection(): void
    {
        $manager = $this->manager();
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        $logger = new RecordingLogger();

        $this->hostFailure(static function () use ($manager, $board): never {
            $card = new Card(5, $board);
            $board->cards->add($card);
            $manager->persist($card);

            throw new DomainException('refused');
        }, $logger);

        self::assertNotContains(self::PENDING_NOTICE, array_column($logger->records, 'message'));
        $manager->flush();
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card'));
        self::assertCount(0, $board->cards, 'Loaded afterwards: nothing.');
    }

    /**
     * TA3-2 / TA3-3: the run also removed a loaded element: only the brought one is taken out; the removal stays (the
     * collection is still dirty) and the notice says so.
     */
    private function scenarioTheRunsOtherCollectionChangeStays(): void
    {
        $this->connection->insert('ddrb_card', ['id' => 1, 'board_id' => 1]);
        $manager = $this->manager();
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        self::assertCount(1, $board->cards);
        $logger = new RecordingLogger();

        $this->hostFailure(static function () use ($manager, $board): never {
            $loaded = $board->cards->first();
            self::assertInstanceOf(Card::class, $loaded);
            $board->cards->removeElement($loaded);
            $card = new Card(5, $board);
            $board->cards->add($card);
            $manager->persist($card);

            throw new DomainException('refused');
        }, $logger);

        self::assertCount(0, $board->cards, 'The brought card left; the run\'s removal stays.');
        self::assertContains(self::PENDING_NOTICE, array_column($logger->records, 'message'));
    }

    /** TA3-2: a to-one of the host's entity the run pointed at a new entity gets its original value back. */
    private function scenarioAToOneGetsItsOriginalValueBack(): void
    {
        $manager = $this->manager();
        $note    = $manager->find(Note::class, 1);
        self::assertInstanceOf(Note::class, $note);
        $original = $note->counter;
        $logger   = new RecordingLogger();

        $this->hostFailure(static function () use ($manager, $note): never {
            $counter = new Counter(9);
            $manager->persist($counter);
            $note->counter = $counter;

            throw new DomainException('refused');
        }, $logger);

        self::assertSame($original, $note->counter);
        self::assertNotContains(self::PENDING_NOTICE, array_column($logger->records, 'message'));
        $manager->flush();
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 9'));
        self::assertSame(1, (int) $this->freshValue('SELECT counter_id FROM ddrb_note WHERE id = 1'));
    }

    /**
     * TA3-6 (and TA3-7): a host manager with pending changes (an edit, a persist, a remove) is refused before the run:
     * nothing runs, no transaction is opened, and the host's work is flushed intact afterwards. A failure after a
     * flush inside the run (or at the commit) can therefore never take the host's work with it.
     */
    private function scenarioAHostWithPendingChangesIsRefused(): void
    {
        $manager = $this->manager();
        $edited  = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $edited);
        $removed = $manager->find(Note::class, 1);
        self::assertInstanceOf(Note::class, $removed);
        $created = new Counter(61, 6);
        $ran     = 0;
        $runner  = DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry);
        $work    = static function () use (&$ran): void {
            ++$ran;
        };

        foreach ([
            static function () use ($edited): void {
                $edited->value = 11;
            },
            static function () use ($manager, $created): void {
                $manager->persist($created);
            },
            static function () use ($manager, $removed): void {
                $manager->remove($removed);
            },
        ] as $pending) {
            $pending();

            $refused = null;

            try {
                $runner->run($work);
            } catch (Throwable $throwable) {
                $refused = $throwable;
            }

            self::assertInstanceOf(LogicException::class, $refused, 'A host manager with pending changes was not refused.');
            self::assertSame(DoctrineTransactionRunner::HOST_PENDING_MESSAGE, $refused->getMessage());

            self::assertSame(0, $this->connection->getTransactionNestingLevel());
        }

        self::assertSame(0, $ran, 'The unit never ran.');
        $manager->flush();
        self::assertSame(11, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 61'));
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_note WHERE id = 1'));
        $token = bin2hex(random_bytes(4));
        self::assertSame($token, $runner->run(static fn (): string => $token), 'A clean manager runs.');
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

    /**
     * TA-3 final, Info 1: a host manager whose change sets cannot be computed (computeChangeSets() cascade-persists a
     * new Card the host added to a Board, and a prePersist listener throws) is refused, and that exception is kept as
     * the previous one of the LogicException.
     */
    private function scenarioAnUncomputableHostIsRefusedWithItsCause(): void
    {
        $manager = $this->manager();
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        $cause    = new RuntimeException('prePersist refused');
        $listener = new class($cause) {
            public function __construct(private readonly RuntimeException $cause)
            {
            }

            public function prePersist(): never
            {
                throw $this->cause;
            }
        };
        $manager->getEventManager()->addEventListener([Events::prePersist], $listener);
        $board->cards->add(new Card(63, $board));   // reached by cascade persist inside computeChangeSets()
        $ran     = 0;
        $runner  = DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry);
        $refused = null;

        try {
            $runner->run(static function () use (&$ran): void {
                ++$ran;
            });
        } catch (Throwable $throwable) {
            $refused = $throwable;
        } finally {
            $manager->getEventManager()->removeEventListener([Events::prePersist], $listener);
        }

        self::assertInstanceOf(LogicException::class, $refused, 'An uncomputable host manager was not refused.');
        self::assertSame(DoctrineTransactionRunner::HOST_PENDING_MESSAGE, $refused->getMessage());
        self::assertSame($cause, $refused->getPrevious(), 'The cause is kept.');
        self::assertSame(0, $ran, 'The unit never ran.');
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card WHERE id = 63'));

        $manager->clear();   // the host's decision after the refusal
        $token = bin2hex(random_bytes(4));
        self::assertSame($token, $runner->run(static fn (): string => $token), 'A clean manager runs.');
    }

    /**
     * XD-1 (1.9.2): orphan removals are pending host work. clear() on an inverse one-to-many with orphanRemoval, and an
     * inverse one-to-one with orphanRemoval set to null, schedule nothing but the orphan's removal, and the run's flush
     * would delete the host's rows inside a transaction that may roll back. Both are refused before the run; the same
     * loaded, untouched entities are accepted, and the host's own flush still deletes the orphans afterwards. After that
     * flush ORM keeps scheduling the deleted Badge again at every computeChangeSets() (the Profile's original data still
     * holds it), but no flush deletes anything: that stale orphan is not pending work, and the next run is accepted.
     */
    private function scenarioAHostWithPendingOrphanRemovalsIsRefused(): void
    {
        $this->connection->insert('ddrb_card', ['id' => 71, 'board_id' => 1]);
        $this->connection->insert('ddrb_profile', ['id' => 1]);
        $this->connection->insert('ddrb_badge', ['id' => 1, 'profile_id' => 1]);
        $manager = $this->manager();
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        self::assertCount(1, $board->cards);
        $profile = $manager->find(Profile::class, 1);
        self::assertInstanceOf(Profile::class, $profile);
        self::assertInstanceOf(Badge::class, $profile->badge);
        $ran    = 0;
        $runner = DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry);
        $token  = bin2hex(random_bytes(4));
        self::assertSame($token, $runner->run(static fn (): string => $token), 'Loaded, untouched orphanRemoval associations are no pending work.');

        foreach ([
            'ddrb_card' => static function () use ($board): void {
                $board->cards->clear();
            },
            'ddrb_badge' => static function () use ($profile): void {
                $profile->badge = null;
            },
        ] as $table => $pending) {
            $pending();

            $refused = null;

            try {
                $runner->run(static function () use (&$ran): void {
                    ++$ran;
                });
            } catch (Throwable $throwable) {
                $refused = $throwable;
            }

            self::assertInstanceOf(LogicException::class, $refused, 'A host manager with a pending orphan removal was not refused.');
            self::assertSame(DoctrineTransactionRunner::HOST_PENDING_MESSAGE, $refused->getMessage());
            self::assertNull($refused->getPrevious());
            self::assertSame(0, $this->connection->getTransactionNestingLevel());
            self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ' . $table), 'The host\'s orphan row is not deleted by the refusal.');

            $manager->flush();   // the host's own decision: its orphan goes
            self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ' . $table));
            self::assertSame($token, $runner->run(static fn (): string => $token), 'A clean manager runs (a stale orphan is no pending work).');
        }

        self::assertSame($token, $runner->run(static fn (): string => $token), 'Still accepted on the next run.');
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card') + (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_badge'));
        self::assertSame(0, $ran, 'The unit never ran over pending orphan removals.');
        self::assertTrue($manager->isOpen());
        self::assertTrue($manager->contains($board), 'The refusal never clears the host\'s manager.');
        self::assertTrue($manager->contains($profile));
    }

    /** TA3-3: a field the run changed on the host's entity stays (not cleared, not refreshed), with the notice. */
    private function scenarioAFieldTheRunChangedStaysWithANotice(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $host);
        $other = $manager->getReference(Counter::class, 2);   // an uninitialized proxy of the host's: never loaded
        self::assertInstanceOf(Counter::class, $other);
        $logger = new RecordingLogger();

        $this->hostFailure(static function () use ($host): never {
            $host->value = 99;

            throw new DomainException('refused');
        }, $logger);

        self::assertTrue($manager->contains($host));
        self::assertTrue($manager->contains($other));
        self::assertTrue($manager->getUnitOfWork()->isUninitializedObject($other), 'No query for the host\'s proxy.');
        self::assertSame(99, $host->value);
        self::assertContains(['level' => 'notice', 'message' => self::PENDING_NOTICE, 'context' => ['manager' => null, 'entities' => 1]], $logger->records);
    }

    /**
     * @param callable(): void $operation
     */
    private function hostFailure(callable $operation, ?RecordingLogger $logger = null): ?Throwable
    {
        try {
            DoctrineTransactionRunner::forHostEntityManager($this->manager(), $this->registry, null, 1, $logger)->run($operation);
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }

    /**
     * Audit VQ4-1 / P25 (1.6.0 to 1.7.2): the run loads counter 1, remove()s it and fails before its flush. The removal
     * is forgotten with the run: the host's next flush keeps the row, and a find() loads it again.
     */
    private function scenarioARemovalOfTheRunsOwnEntityIsForgotten(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);
        $removed = null;

        try {
            DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry)->run(static function () use ($manager, &$removed): never {
                $removed = $manager->find(Counter::class, 1);
                self::assertInstanceOf(Counter::class, $removed);
                $manager->remove($removed);

                throw new RuntimeException('failure before the flush');
            });
        } catch (RuntimeException) {
        }

        self::assertSame([], $manager->getUnitOfWork()->getScheduledEntityDeletions());
        self::assertInstanceOf(Counter::class, $removed);
        self::assertFalse($manager->contains($removed));
        $manager->flush(); // the host's own next flush
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 1'));
        $again = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $again);
        self::assertNotSame($removed, $again);
        self::assertTrue($manager->contains($host));
    }

    /**
     * The run removed an entity the host managed before it: like any other change the run made to the caller's
     * entities, it stays (the host decides; the runner never clears an open host manager).
     */
    private function scenarioARemovalOfAHostEntityStays(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        try {
            DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry)->run(static function () use ($manager, $host): never {
                $manager->remove($host);

                throw new RuntimeException('failure before the flush');
            });
        } catch (RuntimeException) {
        }

        self::assertTrue($manager->getUnitOfWork()->isScheduledForDelete($host));
    }
}
