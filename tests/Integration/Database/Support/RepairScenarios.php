<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\Events;
use Nowo\TransactionDoctrine\ConnectionRepairer;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use ReflectionMethod;
use RuntimeException;

use function version_compare;

/**
 * 1.8 repairs, shared by the real-database suite (MySQL, MariaDB, PostgreSQL) and the unit suite (SQLite file):
 *
 * - the rollback-only flag survives Connection::close() on DBAL 3 and DBAL 4: after the repairer closes a connection
 *   whose nested rollback failed (the server dropped the savepoints), the next transaction must still commit;
 * - audit VD2-4: a write unit's replay never clears the host's open entity manager; the host's entities stay managed
 *   and the ones the failed attempt changed are refreshed.
 *
 * @phpstan-require-extends DatabaseTestCase
 */
trait RepairScenarios
{
    /**
     * A nested unit marked the transaction rollback-only, then the server dropped its savepoints (a MySQL / MariaDB
     * deadlock does; here the savepoint is released by hand): the nested rollback fails, the repairer closes.
     */
    private function scenarioTheFlagDoesNotOutliveAClosedConnection(bool $betweenRequests): void
    {
        $connection = $this->connection;
        $this->useSavepoints($connection);
        $connection->beginTransaction();
        $connection->beginTransaction();
        if ($betweenRequests) {
            $connection->beginTransaction();
        }

        $connection->setRollbackOnly();
        $connection->executeStatement('RELEASE SAVEPOINT ' . $this->savepoint($connection));

        $repairer = new ConnectionRepairer();
        $action   = $betweenRequests ? $repairer->betweenRequests($connection) : $repairer->afterFailure($connection, 0);

        self::assertSame(ConnectionRepairer::ACTION_CLOSED, $action);
        self::assertSame(0, $connection->getTransactionNestingLevel());

        // A plain transaction on the reopened connection: before 1.8, CommitFailedRollbackOnly.
        $connection->beginTransaction();
        $connection->insert('ddrb_counter', ['id' => 6, 'counter_value' => 6]);
        $connection->commit();
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 6'));
    }

    /** A flag left by a connection closed without the repairer: the runner clears it after its own BEGIN. */
    private function scenarioTheRunnerClearsAStaleFlag(): void
    {
        $this->connection->beginTransaction();
        $this->connection->setRollbackOnly();
        $this->connection->close();

        DoctrineTransactionRunner::forConnection($this->connection)->run(function (): void {
            $this->connection->insert('ddrb_counter', ['id' => 7, 'counter_value' => 7]);
        });

        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 7'));
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
    }

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
     * Audit VD2-4: the failed attempt removed a host entity. It cannot be brought back without clearing the manager,
     * so the unit gives up (nothing committed: Retry-After set) and the manager keeps the host's other entity.
     */
    private function scenarioAnUnrestorableHostEntityStopsTheReplay(): void
    {
        $manager = $this->manager();
        $kept    = $manager->find(Counter::class, 1);
        $removed = $manager->find(Counter::class, 2);
        self::assertInstanceOf(Counter::class, $kept);
        self::assertInstanceOf(Counter::class, $removed);
        $attempts = 0;

        try {
            (new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0)))->run(static function () use ($manager, $removed, &$attempts): never {
                ++$attempts;
                $manager->remove($removed);

                throw new DeadlockException(FakeDriverException::deadlock(), null);
            });
        } catch (ConcurrencyConflict $conflict) {
            self::assertSame(1, $conflict->attempts);
            self::assertFalse($conflict->committed);
            self::assertNotNull($conflict->retryAfterSeconds());
        }

        self::assertSame(1, $attempts);
        self::assertTrue($manager->isOpen());
        self::assertTrue($manager->contains($kept), 'The manager was not cleared.');
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

    private function useSavepoints(Connection $connection): void
    {
        // DBAL 3 nests without savepoints unless told otherwise; DBAL 4 always uses them.
        if (version_compare((string) InstalledVersions::getVersion('doctrine/dbal'), '4.0.0', '<')) {
            $connection->setNestTransactionsWithSavepoints(true);
        }
    }

    /** The savepoint DBAL created for the current level (its name differs between DBAL versions). */
    private function savepoint(Connection $connection): string
    {
        return (string) (new ReflectionMethod(Connection::class, '_getNestedTransactionSavePointName'))->invoke($connection);
    }
}
