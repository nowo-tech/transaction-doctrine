<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\RollbackOnlyScenarios;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The 1.7.2 rollback-only scenarios on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (RollbackOnlySqliteTest runs them
 * on SQLite).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RollbackOnlyDatabaseTest extends DatabaseTestCase
{
    use RollbackOnlyScenarios;

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
    public function testTheFlagIsClearedWithAutoCommitOff(string $server): void
    {
        $this->boot($server);
        $this->scenarioTheFlagIsClearedWithAutoCommitOff();
    }

    #[DataProvider('allServers')]
    public function testTheRunnerClearsAStaleFlag(string $server): void
    {
        $this->boot($server);
        $this->scenarioTheRunnerClearsAStaleFlag();
    }
}
