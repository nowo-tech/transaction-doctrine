<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\Events;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use RuntimeException;

/**
 * 1.8, audits VD2-4 / VQ: a write unit's replay never clears the host's open entity manager; it is put back to what
 * the database holds (the attempt's entities detached, the host's reloaded), or the unit gives up with
 * ConcurrencyConflict after restoring all it can. Shared by the real-database suite (MySQL, MariaDB, PostgreSQL) and the
 * unit suite (SQLite file).
 *
 * @phpstan-require-extends DatabaseTestCase
 */
trait RepairScenarios
{
    /**
     * Audit VD2-4: the host loaded counter 2 and a board before the unit. The failed attempt changes counter 2, loads
     * counter 1 and the board's cards, and persists a new card; the replay starts from the committed state with the
     * host's entities still managed (the same objects), the attempt's entities detached, and commits.
     */
    private function scenarioAReplayKeepsTheHostsEntities(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Counter::class, $host);
        self::assertInstanceOf(Board::class, $board);
        $seen = [];

        $result = (new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0)))->run(static function (int $attempt) use ($manager, $host, $board, &$seen): int {
            $counter = $manager->find(Counter::class, 1);
            self::assertInstanceOf(Counter::class, $counter);
            $seen[] = $counter;

            if ($attempt === 1) {
                $host->value = 99;
                self::assertCount(0, $board->cards);
                $card = new Card(5, $board);
                $board->cards->add($card);
                $manager->persist($card);

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            self::assertTrue($manager->contains($host), 'The host entity is still managed.');
            self::assertSame(0, $host->value, 'The failed attempt\'s change is gone.');
            self::assertTrue($manager->contains($board));
            self::assertCount(0, $board->cards, 'The failed attempt\'s card is gone.');
            ++$counter->value;
            $manager->flush();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertTrue($manager->contains($host));
        self::assertNotSame($seen[0], $seen[1], 'What the failed attempt loaded was detached.');
        self::assertFalse($manager->contains($seen[0]));
        self::assertSame(1, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
        self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card'));
        self::assertSame(0, $this->registry->resets);
    }

    /**
     * A proxy the host held, initialised inside the failed attempt's transaction (it saw that transaction's write):
     * refreshed from the committed row, still managed; the replay's increment starts from 0, not from the rolled-back 40.
     */
    private function scenarioAReinitialisedHostProxyIsRefreshed(): void
    {
        $manager = $this->manager();
        $proxy   = $manager->getReference(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $proxy);
        // An untouched host entity with a collection: compared, found unchanged, left as it is.
        $board = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);

        $result = (new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0)))->run(function (int $attempt) use ($manager, $proxy): int {
            if ($attempt === 1) {
                $this->connection->beginTransaction();
                $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 40 WHERE id = 1');
                self::assertSame(40, $proxy->value);

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            self::assertTrue($manager->contains($proxy));
            ++$proxy->value;
            $manager->flush();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertTrue($manager->contains($board));
        self::assertSame(1, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
    }

    /** A host entity's refresh fails (here a postLoad listener throws): the unit gives up, the manager kept open. */
    private function scenarioAFailedRefreshStopsTheReplay(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);
        $failing = new class {
            public bool $armed = false;

            public function postLoad(): void
            {
                if ($this->armed) {
                    throw new RuntimeException('listener failure');
                }
            }
        };
        $manager->getEventManager()->addEventListener([Events::postLoad], $failing);

        try {
            (new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0)))->run(static function () use ($host, $failing): never {
                $host->value    = 5;
                $failing->armed = true;

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            });
        } catch (ConcurrencyConflict $conflict) {
            self::assertSame(1, $conflict->attempts);
            self::assertNull($conflict->resetFailure, 'Given up by the restore, not by the pending-changes check.');
            $failing->armed = false;
        } finally {
            $manager->getEventManager()->removeEventListener([Events::postLoad], $failing);
        }

        self::assertFalse($failing->armed, 'The unit gave up with a ConcurrencyConflict.');

        self::assertTrue($manager->isOpen());
        self::assertTrue($manager->contains($host), 'The manager was not cleared.');
    }

    /** A host board's collection the failed attempt initialised holds what it loaded: the board is refreshed. */
    private function scenarioACollectionTheAttemptLoadedIsReloaded(): void
    {
        $this->connection->insert('ddrb_card', ['id' => 9, 'board_id' => 1]);
        $manager = $this->manager();
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        $cards = [];

        $result = (new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0)))->run(static function (int $attempt) use ($manager, $board, &$cards): int {
            self::assertCount(1, $board->cards);
            $cards[] = $board->cards->first();

            if ($attempt === 1) {
                $manager->persist(new Counter(9));

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            self::assertSame([], $manager->getUnitOfWork()->getScheduledEntityInsertions());
            $manager->flush();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertTrue($manager->contains($board));
        self::assertInstanceOf(Card::class, $cards[1]);
        self::assertNotSame($cards[0], $cards[1]);
        self::assertTrue($manager->contains($cards[1]));
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 9'));
    }

    /**
     * The host's manager was closed by the failed attempt: it is reset (a closed manager is not the host's open one),
     * and the next replays start on the new manager, never "restoring" the old one's entities into it.
     */
    private function scenarioAResetHostManagerIsNotRestored(): void
    {
        $host = $this->manager()->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $result = (new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0)))->run(function (int $attempt): int {
            $manager = $this->manager();
            if ($attempt === 1) {
                $manager->close();
            }

            if ($attempt < 3) {
                $manager->find(Counter::class, 1);

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            return $attempt;
        });

        self::assertSame(3, $result);
        self::assertSame(1, $this->registry->resets);
        self::assertFalse($this->manager()->contains($host));
    }

    /**
     * Audits VQ-1 / P1, P1c: the attempt re-finds the host's entity (the same object), changes it and flushes inside
     * its transaction, then fails. The flush moved Doctrine's original data to the rolled-back value, so the entity is
     * reloaded: the replay starts from 0 and its own write of 5 is not lost (1.7.1 wrote it; the first 1.8 draft did not).
     */
    private function scenarioAFlushedHostEntityIsReloaded(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $result = $this->unit()->run(function (int $attempt) use ($manager, $host): int {
            $counter = $manager->find(Counter::class, 2);
            self::assertSame($host, $counter);
            self::assertSame(0, $host->value, 'Attempt ' . $attempt . ' starts from the stored value.');
            $this->connection->beginTransaction();
            $host->value = 5;
            $manager->flush();
            if ($attempt === 1) {
                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            $this->connection->commit();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertTrue($manager->contains($host));
        self::assertSame(5, $host->value);
        self::assertSame(5, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
    }

    /** Audit VQ-1 / P1b: an increment flushed by the failed attempt is not counted twice. */
    private function scenarioAFlushedIncrementIsNotCountedTwice(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $this->unit()->run(function (int $attempt) use ($manager, $host): void {
            $this->connection->beginTransaction();
            ++$host->value;
            $manager->flush();
            if ($attempt === 1) {
                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            $this->connection->commit();
        });

        self::assertSame(1, $host->value);
        self::assertSame(1, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
    }

    /** Audit VQ-1 / P4: a versioned host entity flushed by the failed attempt is reloaded with the stored version. */
    private function scenarioAVersionedHostEntityKeepsItsStoredVersion(): void
    {
        $manager = $this->manager();
        $doc     = $manager->find(Doc::class, 1);
        self::assertInstanceOf(Doc::class, $doc);

        $this->unit()->run(function (int $attempt) use ($manager, $doc): void {
            self::assertSame(1, $doc->version);
            self::assertSame('a', $doc->label);
            $this->connection->beginTransaction();
            $doc->label = 'b' . $attempt;
            $manager->flush();
            if ($attempt === 1) {
                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            $this->connection->commit();
        });

        self::assertSame('b2', $this->freshValue('SELECT label FROM ddrb_doc WHERE id = 1'));
        self::assertSame(2, (int) $this->freshValue('SELECT version FROM ddrb_doc WHERE id = 1'));
        self::assertSame(2, $doc->version);
    }

    /** A host entity a flush of the failed attempt deleted: the rollback restored its row, it is managed again. */
    private function scenarioAFlushedRemovalIsUndone(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $result = $this->unit()->run(function (int $attempt) use ($manager, $host): int {
            self::assertTrue($manager->contains($host));
            if ($attempt === 1) {
                $this->connection->beginTransaction();
                $manager->remove($host);
                $manager->flush();

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            ++$host->value;
            $manager->flush();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame(1, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
    }

    /**
     * Audit VQ-2 / P2, P9: removeElement() or clear() on an orphanRemoval collection schedules the orphan's removal at
     * once. The failed attempt's removal is cancelled: the replay's flush does not delete the host's card.
     */
    private function scenarioARolledBackOrphanRemovalIsCancelled(bool $clear): void
    {
        $this->connection->insert('ddrb_card', ['id' => 9, 'board_id' => 1]);
        $manager = $this->manager();
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        self::assertCount(1, $board->cards);
        $card = $board->cards->first();
        self::assertInstanceOf(Card::class, $card);

        $result = $this->unit()->run(static function (int $attempt) use ($manager, $board, $card, $clear): int {
            if ($attempt === 1) {
                $clear ? $board->cards->clear() : $board->cards->removeElement($card);

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            $counter = $manager->find(Counter::class, 1);
            self::assertInstanceOf(Counter::class, $counter);
            ++$counter->value;
            $manager->flush();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card WHERE id = 9'));
        self::assertTrue($manager->contains($card));
        self::assertCount(1, $board->cards);
    }

    /**
     * Audit VQ-3 / P3: the failed attempt removed one host entity and changed another without flushing. The removal is
     * cancelled and the change reverted; the replay starts clean and commits; nothing of the attempt is written.
     */
    private function scenarioAnUnflushedRemovalIsCancelled(): void
    {
        $manager = $this->manager();
        $removed = $manager->find(Counter::class, 1);
        $changed = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $removed);
        self::assertInstanceOf(Counter::class, $changed);

        $result = $this->unit()->run(static function (int $attempt) use ($manager, $removed, $changed): int {
            if ($attempt === 1) {
                $manager->remove($removed);
                $changed->value = 7;

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            self::assertTrue($manager->contains($removed));
            self::assertSame(0, $changed->value);
            self::assertSame([], $manager->getUnitOfWork()->getScheduledEntityDeletions());
            $manager->flush();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame(2, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id IN (1, 2)'));
        self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
    }

    /**
     * Audits VQ-3 / P7, P10: the failed attempt detached a host entity (it cannot be managed again: its original data is
     * gone), changed another one and added a new card to the host's board. The unit gives up with ConcurrencyConflict,
     * but only after restoring everything else: the change reverted, the card unlinked and detached. A later flush by
     * the host writes nothing of the attempt.
     */
    private function scenarioAnUnrestorableHostEntityStillRestoresTheRest(bool $initialised): void
    {
        $manager  = $this->manager();
        $detached = $manager->find(Counter::class, 1);
        $changed  = $manager->find(Counter::class, 2);
        $board    = $manager->find(Board::class, 1);
        self::assertInstanceOf(Counter::class, $detached);
        self::assertInstanceOf(Counter::class, $changed);
        self::assertInstanceOf(Board::class, $board);
        if ($initialised) {
            self::assertCount(0, $board->cards);
        }

        $conflict = null;
        try {
            $this->unit()->run(static function () use ($manager, $detached, $changed, $board): never {
                $manager->detach($detached);
                $changed->value = 9;
                $card           = new Card(5, $board);
                $board->cards->add($card);
                $manager->persist($card);

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            });
        } catch (ConcurrencyConflict $caught) {
            $conflict = $caught;
        }

        self::assertSame(1, $conflict->attempts);
        self::assertFalse($conflict->committed);
        self::assertNotNull($conflict->retryAfterSeconds());
        self::assertTrue($manager->isOpen());
        self::assertTrue($manager->contains($changed), 'The manager was not cleared.');
        self::assertSame(0, $changed->value);
        self::assertTrue($manager->contains($board));
        self::assertCount(0, $board->cards);

        $manager->flush(); // the host's own next flush
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card'));
        self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
        self::assertSame(0, $this->registry->resets);
    }

    /**
     * Audits VQ-4 / P5, P8: a host card's board pointed at a new board the attempt persisted (detached afterwards). The
     * card is reloaded through its persister (EntityManager::refresh() would cascade into the detached board and fail):
     * the replay sees the stored board and commits.
     */
    private function scenarioAToOneToABroughtEntityIsReloaded(): void
    {
        $this->connection->insert('ddrb_card', ['id' => 9, 'board_id' => 1]);
        $manager = $this->manager();
        $card    = $manager->find(Card::class, 9);
        self::assertInstanceOf(Card::class, $card);
        $board = $card->board;

        $result = $this->unit()->run(static function (int $attempt) use ($manager, $card, $board): int {
            if ($attempt === 1) {
                $other = new Board(2);
                $manager->persist($other);
                $card->board = $other;

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            self::assertSame($board, $card->board);
            $manager->flush();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertTrue($manager->contains($card));
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_board'));
        self::assertSame(1, (int) $this->freshValue('SELECT board_id FROM ddrb_card WHERE id = 9'));
    }

    /** A host entity changed by the attempt whose row is gone: it cannot be reloaded, the unit gives up (409). */
    private function scenarioAGoneRowStopsTheReplay(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $conflict = null;
        try {
            $this->unit()->run(function () use ($host): never {
                $host->value = 5;
                $this->connection->executeStatement('DELETE FROM ddrb_counter WHERE id = 2'); // auto-committed

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            });
        } catch (ConcurrencyConflict $caught) {
            $conflict = $caught;
        }

        self::assertTrue($manager->isOpen());
        self::assertTrue($manager->contains($host), 'The manager was not cleared.');
    }

    /** Audit VQ2 / P1, P1b through DoctrineTransactionRunner::forHostEntityManager(): the flushed write is reloaded. */
    private function scenarioAHostRunnerFlushIsReloaded(bool $increment): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $this->unit()->run(function (int $attempt) use ($manager, $host, $increment): void {
            DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry)->run(static function () use ($manager, $host, $increment, $attempt): void {
                if ($increment) {
                    ++$host->value;
                } else {
                    $host->value = 5;
                }

                $manager->flush();
                if ($attempt === 1) {
                    throw new DeadlockException(FakeDriverException::deadlock(), null);
                }
            });
        });

        $expected = $increment ? 1 : 5;
        self::assertTrue($manager->contains($host));
        self::assertSame($expected, $host->value);
        self::assertSame($expected, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
    }

    /** Audit VQ2 / P5: a parent with a readonly id, a version and a cascade-refresh collection, flushed by the attempt. */
    private function scenarioAReadonlyVersionedParentIsReloaded(): void
    {
        $manager = $this->manager();
        $folder  = $manager->find(Folder::class, 1);
        self::assertInstanceOf(Folder::class, $folder);
        $leaf = $folder->leaves->first();
        self::assertInstanceOf(Leaf::class, $leaf);

        $result = $this->unit()->run(function (int $attempt) use ($manager, $folder, $leaf): int {
            self::assertSame('a', $folder->label);
            self::assertSame(1, $folder->version);
            self::assertSame('c1', $leaf->label);
            if ($attempt === 1) {
                $this->connection->beginTransaction();
                $folder->label = 'zz';
                $leaf->label   = 'yy';
                $manager->flush();

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            self::assertTrue($manager->contains($leaf));
            self::assertTrue($folder->leaves->contains($leaf));

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame(1, $folder->id);
        self::assertSame('a', $this->freshValue('SELECT label FROM ddrb_folder WHERE id = 1'));
        self::assertSame('c1', $this->freshValue('SELECT label FROM ddrb_leaf WHERE id = 1'));
    }

    /** Audit VQ2 / P6: detaching a brought note (cascade detach towards the counter) never detaches the host's counter. */
    private function scenarioACascadeDetachFromABroughtEntityStopsAtTheHost(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $host);

        $result = $this->unit()->run(static function (int $attempt) use ($manager, $host): int {
            $note = $manager->find(Note::class, 1);
            self::assertInstanceOf(Note::class, $note);
            self::assertSame($host, $note->counter);
            if ($attempt === 1) {
                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertTrue($manager->contains($host));
    }

    /**
     * Audit VQ2 / P7: a never-persisted leaf reached only through the host folder's inverse cascade-persist collection.
     * The replay's flush does not insert it.
     */
    private function scenarioANeverPersistedChildIsNotInserted(bool $initialised): void
    {
        $manager = $this->manager();
        $folder  = $manager->find(Folder::class, 1);
        self::assertInstanceOf(Folder::class, $folder);
        if ($initialised) {
            self::assertCount(1, $folder->leaves);
        }

        $result = $this->unit()->run(static function (int $attempt) use ($manager, $folder): int {
            if ($attempt === 1) {
                $folder->leaves->add(new Leaf(5, $folder, 'phantom'));

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            $counter = $manager->find(Counter::class, 1);
            self::assertInstanceOf(Counter::class, $counter);
            ++$counter->value;
            $manager->flush();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_leaf WHERE id = 5'));
        self::assertCount(1, $folder->leaves);
    }

    /** Audit VQ2 / P8: the attempt moved the host's leaf to an existing folder it loaded, on both sides, and flushed. */
    private function scenarioAMoveToALoadedParentIsUndone(): void
    {
        $manager = $this->manager();
        $leaf    = $manager->find(Leaf::class, 1);
        self::assertInstanceOf(Leaf::class, $leaf);
        $parent = $leaf->folder;

        $result = $this->unit()->run(function (int $attempt) use ($manager, $leaf, $parent): int {
            self::assertSame($parent, $leaf->folder);
            if ($attempt === 1) {
                $this->connection->beginTransaction();
                $other = $manager->find(Folder::class, 2);
                self::assertInstanceOf(Folder::class, $other);
                $leaf->folder = $other;
                $other->leaves->add($leaf);
                $manager->flush();

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame(1, $leaf->folder->id);
        self::assertSame(1, (int) $this->freshValue('SELECT folder_id FROM ddrb_leaf WHERE id = 1'));
    }

    /** Audit VQ2 / P11: a readonly-id, versioned host entity deleted by a flush of the attempt is managed again and writable. */
    private function scenarioADeletedReadonlyParentIsWritableAgain(): void
    {
        $manager = $this->manager();
        $folder  = $manager->find(Folder::class, 2);
        self::assertInstanceOf(Folder::class, $folder);

        $result = $this->unit()->run(function (int $attempt) use ($manager, $folder): int {
            if ($attempt === 1) {
                $this->connection->beginTransaction();
                $manager->remove($folder);
                $manager->flush();

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertTrue($manager->contains($folder));
        self::assertSame(2, $folder->id);
        $folder->label = 'after';
        $manager->flush();
        self::assertSame('after', $this->freshValue('SELECT label FROM ddrb_folder WHERE id = 2'));
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_folder WHERE id = 2'));
    }

    /** Audit VQ2 / P12: a host leaf orphan-removed and deleted by a flush of the attempt is back in its folder and writable. */
    private function scenarioAFlushedOrphanRemovalIsUndone(): void
    {
        $manager = $this->manager();
        $folder  = $manager->find(Folder::class, 1);
        self::assertInstanceOf(Folder::class, $folder);
        $leaf = $folder->leaves->first();
        self::assertInstanceOf(Leaf::class, $leaf);

        $result = $this->unit()->run(function (int $attempt) use ($manager, $folder, $leaf): int {
            if ($attempt === 1) {
                $this->connection->beginTransaction();
                $folder->leaves->removeElement($leaf);
                $manager->flush();

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertTrue($manager->contains($leaf));
        self::assertTrue($folder->leaves->contains($leaf));
        $leaf->label = 'edited';
        $manager->flush();
        self::assertSame('edited', $this->freshValue('SELECT label FROM ddrb_leaf WHERE id = 1'));
    }

    /**
     * Audit VQ2-2 / P15: the host holds a counter whose row another session deleted. An attempt that flushed elsewhere
     * reloads every kept entity; the stale, untouched one does not stop the replay.
     */
    private function scenarioAStaleUntouchedHostEntityDoesNotStopTheReplay(): void
    {
        $manager = $this->manager();
        $stale   = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $stale);
        $other = $this->connect();
        $other->executeStatement('DELETE FROM ddrb_counter WHERE id = 2');
        $other->close();

        $result = $this->unit()->run(function (int $attempt) use ($manager): int {
            $this->connection->beginTransaction();
            $counter = $manager->find(Counter::class, 1);
            self::assertInstanceOf(Counter::class, $counter);
            ++$counter->value;
            $manager->flush();
            if ($attempt === 1) {
                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            $this->connection->commit();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertTrue($manager->contains($stale));
        self::assertSame(1, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
    }

    /**
     * Audit VQ2-1 / P16: the attempt loaded an entity itself and removed it without flushing. Its removal is forgotten
     * (the entity detached): the replay goes ahead and no later flush deletes the row.
     */
    private function scenarioARemovalOfTheAttemptsOwnEntityIsForgotten(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $result = $this->unit()->run(static function (int $attempt) use ($manager): int {
            if ($attempt === 1) {
                $own = $manager->find(Counter::class, 1);
                self::assertInstanceOf(Counter::class, $own);
                $manager->remove($own);

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            self::assertSame([], $manager->getUnitOfWork()->getScheduledEntityDeletions());

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame([], $manager->getUnitOfWork()->getScheduledEntityDeletions());
        $manager->flush(); // the host's own next flush
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 1'));
        self::assertTrue($manager->contains($host));
    }

    /** Audit VQ2-3 / P23: computeChangeSets() inside the attempt moves the original data; the change is still undone. */
    private function scenarioAComputedChangeIsStillUndone(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $host);

        $result = $this->unit()->run(static function (int $attempt) use ($manager, $host): int {
            if ($attempt === 1) {
                $host->value = 9;
                $manager->getUnitOfWork()->computeChangeSets();

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            }

            self::assertSame(0, $host->value);
            $manager->flush();

            return $attempt;
        });

        self::assertSame(2, $result);
        self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'));
    }

    private function unit(): WriteUnit
    {
        return new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0));
    }
}
