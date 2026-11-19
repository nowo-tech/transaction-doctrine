<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Counter;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\TwoManagerRegistry;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingDriver;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

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
