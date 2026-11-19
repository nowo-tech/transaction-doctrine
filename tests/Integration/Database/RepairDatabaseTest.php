<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\RepairScenarios;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The 1.8 repair scenarios on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (RepairSqliteTest runs them on SQLite).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RepairDatabaseTest extends DatabaseTestCase
{
    use RepairScenarios;

    #[DataProvider('allServers')]
    public function testTheFlagDoesNotOutliveAClosedConnectionAfterAFailure(string $server): void
    {
        $this->boot($server);
        $this->scenarioTheFlagDoesNotOutliveAClosedConnection(false);
    }

    #[DataProvider('allServers')]
    public function testTheFlagDoesNotOutliveAClosedConnectionBetweenRequests(string $server): void
    {
        $this->boot($server);
        $this->scenarioTheFlagDoesNotOutliveAClosedConnection(true);
    }

    #[DataProvider('allServers')]
    public function testTheRunnerClearsAStaleFlag(string $server): void
    {
        $this->boot($server);
        $this->scenarioTheRunnerClearsAStaleFlag();
    }

    #[DataProvider('allServers')]
    public function testAReplayKeepsTheHostsEntities(string $server): void
    {
        $this->boot($server);
        $this->scenarioAReplayKeepsTheHostsEntities();
    }

    #[DataProvider('allServers')]
    public function testAnUnrestorableHostEntityStopsTheReplay(string $server): void
    {
        $this->boot($server);
        $this->scenarioAnUnrestorableHostEntityStopsTheReplay();
    }

    #[DataProvider('allServers')]
    public function testAReinitialisedHostProxyIsRefreshed(string $server): void
    {
        $this->boot($server);
        $this->scenarioAReinitialisedHostProxyIsRefreshed();
    }

    #[DataProvider('allServers')]
    public function testAFailedRefreshStopsTheReplay(string $server): void
    {
        $this->boot($server);
        $this->scenarioAFailedRefreshStopsTheReplay();
    }

    #[DataProvider('allServers')]
    public function testAResetHostManagerIsNotRestored(string $server): void
    {
        $this->boot($server);
        $this->scenarioAResetHostManagerIsNotRestored();
    }

    #[DataProvider('allServers')]
    public function testACollectionTheAttemptLoadedIsReloaded(string $server): void
    {
        $this->boot($server);
        $this->scenarioACollectionTheAttemptLoadedIsReloaded();
    }
}
