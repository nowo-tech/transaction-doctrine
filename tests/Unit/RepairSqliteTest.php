<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\RepairScenarios;

use function getmypid;
use function is_file;
use function sys_get_temp_dir;
use function unlink;

/**
 * The 1.8 repair scenarios (rollback-only flag after a close, audit VD2-4 replays that keep the host's entities) on a
 * real ORM over a SQLite file, so the unit suite covers them; RepairDatabaseTest runs them on MySQL, MariaDB and
 * PostgreSQL.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RepairSqliteTest extends DatabaseTestCase
{
    use RepairScenarios;

    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/nowo-td-repair-' . getmypid() . '.sqlite';
        $this->bootUrl('sqlite:///' . $this->file);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testTheFlagDoesNotOutliveAClosedConnectionAfterAFailure(): void
    {
        $this->scenarioTheFlagDoesNotOutliveAClosedConnection(false);
    }

    public function testTheFlagDoesNotOutliveAClosedConnectionBetweenRequests(): void
    {
        $this->scenarioTheFlagDoesNotOutliveAClosedConnection(true);
    }

    public function testTheRunnerClearsAStaleFlag(): void
    {
        $this->scenarioTheRunnerClearsAStaleFlag();
    }

    public function testAReplayKeepsTheHostsEntities(): void
    {
        $this->scenarioAReplayKeepsTheHostsEntities();
    }

    public function testAnUnrestorableHostEntityStopsTheReplay(): void
    {
        $this->scenarioAnUnrestorableHostEntityStopsTheReplay();
    }

    public function testAReinitialisedHostProxyIsRefreshed(): void
    {
        $this->scenarioAReinitialisedHostProxyIsRefreshed();
    }

    public function testAFailedRefreshStopsTheReplay(): void
    {
        $this->scenarioAFailedRefreshStopsTheReplay();
    }

    public function testAResetHostManagerIsNotRestored(): void
    {
        $this->scenarioAResetHostManagerIsNotRestored();
    }

    public function testACollectionTheAttemptLoadedIsReloaded(): void
    {
        $this->scenarioACollectionTheAttemptLoadedIsReloaded();
    }
}
