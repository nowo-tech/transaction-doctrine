<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use DomainException;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use Throwable;

use function array_column;
use function array_values;

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
     * TA3-1: the run's new Note and a run-loaded Note both cascade detach into the host's Counter, which has a pending
     * edit. The Counter stays managed and its edit is flushed; the run's entities are forgotten.
     */
    private function scenarioADetachNeverCascadesIntoTheHostsEntity(): void
    {
        $manager = $this->manager();
        $host    = $manager->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $host);
        $host->value = 11;   // the host's own pending edit
        $note        = new Note(70, $host);

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

        $manager->flush();
        self::assertSame(11, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), 'The host\'s edit survives.');
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

    /** TA3-2 / TA3-3: the host's own addition to the same collection stays, and the notice says changes are pending. */
    private function scenarioTheHostsOwnCollectionChangeStays(): void
    {
        $manager = $this->manager();
        $board   = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $board);
        $own = new Card(6, $board);
        $board->cards->add($own);
        $manager->persist($own);
        $logger = new RecordingLogger();

        $this->hostFailure(static function () use ($manager, $board): never {
            $card = new Card(5, $board);
            $board->cards->add($card);
            $manager->persist($card);

            throw new DomainException('refused');
        }, $logger);

        self::assertSame([$own], array_values($board->cards->toArray()));
        self::assertContains(self::PENDING_NOTICE, array_column($logger->records, 'message'));
        $manager->flush();
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card WHERE id = 6'));
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card WHERE id = 5'));
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
     * A new host entity (scheduled for insertion before the run) holds a plain collection: the run's child leaves it.
     * A to-one of a new host entity has no original value: it is left (documented) while its target is detached.
     */
    private function scenarioNewHostEntities(): void
    {
        $manager = $this->manager();
        $board   = new Board(2);
        $manager->persist($board);
        $existing = $manager->find(Board::class, 1);
        self::assertInstanceOf(Board::class, $existing);
        $card = new Card(8, $existing);
        $manager->persist($card);
        $moved = new Board(3);

        $this->hostFailure(static function () use ($manager, $board, $card, $moved): never {
            $child = new Card(7, $board);
            $board->cards->add($child);
            $manager->persist($child);
            $manager->persist($moved);
            $card->board = $moved;

            throw new DomainException('refused');
        });

        self::assertCount(0, $board->cards);
        self::assertTrue($manager->contains($board));
        self::assertFalse($manager->contains($moved));
        self::assertSame($moved, $card->board, 'No original value to restore: the caller decides.');

        $card->board = $existing;
        $manager->flush();
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_board WHERE id = 2'));
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_card WHERE id = 7'));
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_board WHERE id = 3'));
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
}
