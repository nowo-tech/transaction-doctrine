<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\CallbackDispatcher;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Counter;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\TwoManagerRegistry;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingDriver;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;

use function str_starts_with;

/**
 * 1.7 follow-ups against real MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, with two connections:
 *
 * - audit UD2-L-2: a write unit scoped to a bundle's own entity manager replays a real lock wait timeout without
 *   clearing the host's manager; unscoped (the default) it clears both;
 * - audit UE-CF-1: with commit tracking on, DBAL 3's Connection::commit() returns the driver's `true` (DBAL 4: void),
 *   the commit is recorded, and a second session sees it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class FollowUpsDatabaseTest extends DatabaseTestCase
{
    #[DataProvider('allServers')]
    public function testAUnitScopedToItsOwnManagerLeavesTheHostManagerAlone(string $server): void
    {
        foreach ([true, false] as $scoped) {
            $this->boot($server);
            $privateConnection = $this->connect();
            $privateConnection->executeStatement($this->isPostgreSql() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
            $registry    = new TwoManagerRegistry($this->connection, $privateConnection, static fn (Connection $connection): EntityManagerInterface => self::entityManager($connection));
            $hostCounter = $registry->entityManager('default')->find(Counter::class, 2);
            self::assertInstanceOf(Counter::class, $hostCounter);
            self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
            self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 7 WHERE id = 1'));
            $unit   = new WriteUnit($registry, new WriteUnitPolicy(3, 0, 0, 0));
            $unit   = $scoped ? $unit->forManagers('private') : $unit;
            $loaded = [];

            $result = $unit->run(function (int $attempt) use ($registry, &$loaded): int {
                if ($attempt === 2) {
                    self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
                }

                $manager = $registry->entityManager('private');
                $counter = $manager->find(Counter::class, 1);
                self::assertInstanceOf(Counter::class, $counter);
                $loaded[] = $counter;
                ++$counter->value;
                $manager->flush();

                return $attempt;
            });

            $label = $scoped ? 'scoped' : 'unscoped';
            self::assertSame(2, $result, $label);
            self::assertSame($scoped, $registry->entityManager('default')->contains($hostCounter), $label);
            self::assertFalse($registry->entityManager('private')->contains($loaded[0]), $label);
            self::assertSame(8, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'), $label);
            self::assertSame(0, $privateConnection->getTransactionNestingLevel(), $label);
            $privateConnection->close();
            $this->tearDown();
        }
    }

    /**
     * Audit UI-1: a scoped unit whose retry listener opens a transaction on the host's connection and throws, or
     * persists through the host's manager, is abandoned with nothing left behind: level 0 on every connection, no
     * pending host change, and the listener's write rolled back.
     */
    #[DataProvider('allServers')]
    public function testAnAbandonedScopedReplayLeavesNothingBehind(string $server): void
    {
        foreach (['begins a transaction and throws', 'persists through the host manager'] as $case) {
            $this->boot($server);
            $privateConnection = $this->connect();
            $privateConnection->executeStatement($this->isPostgreSql() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
            $registry = new TwoManagerRegistry($this->connection, $privateConnection, static fn (Connection $connection): EntityManagerInterface => self::entityManager($connection));
            self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
            self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 7 WHERE id = 1'));
            $listener = $case === 'persists through the host manager'
                ? static function () use ($registry): void {
                    $registry->entityManager('default')->persist(new Counter(9, 9));
                }
            : function (): never {
                $this->connection->beginTransaction();
                $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 3 WHERE id = 2');

                throw new RuntimeException('retry listener failed');
            };
            $unit   = new WriteUnit($registry, new WriteUnitPolicy(3, 0, 0, 0), null, null, null, null, null, [], new CallbackDispatcher($listener), ['private']);
            $caught = null;

            try {
                $unit->run(static function () use ($privateConnection): void {
                    $privateConnection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1');
                });
            } catch (ConcurrencyConflict $conflict) {
                $caught = $conflict;
            } finally {
                $this->otherSession()->execute('ROLLBACK');
            }

            self::assertInstanceOf(ConcurrencyConflict::class, $caught, $case);
            self::assertInstanceOf($case === 'persists through the host manager' ? LogicException::class : RuntimeException::class, $caught->resetFailure, $case);
            self::assertSame(0, $this->connection->getTransactionNestingLevel(), $case);
            self::assertSame(0, $privateConnection->getTransactionNestingLevel(), $case);
            self::assertSame([], $registry->entityManager('default')->getUnitOfWork()->getScheduledEntityInsertions(), $case);
            self::assertSame(0, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 2'), $case);
            self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 9'), $case);
            $privateConnection->close();
            $this->tearDown();
        }
    }

    #[DataProvider('allServers')]
    public function testDbal3CommitReturnsTheDriversResultWithCommitTracking(string $server): void
    {
        $ledger       = new CommitLedger();
        $this->ledger = $ledger;
        $this->boot($server);
        $before = $ledger->count();

        $this->connection->beginTransaction();
        $this->connection->executeStatement('INSERT INTO ddrb_board (id) VALUES (40)');
        $result = (new ReflectionMethod($this->connection, 'commit'))->invoke($this->connection);

        $dbal3 = str_starts_with((string) InstalledVersions::getVersion('doctrine/dbal'), '3.');
        self::assertSame($dbal3, CommitTrackingDriver::driverCommitReturnsResult());
        self::assertSame($dbal3 ? true : null, $result);
        self::assertSame($before + 1, $ledger->count());
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_board WHERE id = 40'));
    }
}
