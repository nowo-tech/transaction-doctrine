<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\RollbackOnlyScenarios;

use function getmypid;
use function is_file;
use function sys_get_temp_dir;
use function unlink;

/**
 * The 1.7.2 rollback-only scenarios on a SQLite file, so the unit suite covers them; RollbackOnlyDatabaseTest runs
 * them on MySQL, MariaDB and PostgreSQL.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RollbackOnlySqliteTest extends DatabaseTestCase
{
    use RollbackOnlyScenarios;

    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/nowo-td-flag-' . getmypid() . '.sqlite';
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

    public function testTheFlagIsClearedWithAutoCommitOff(): void
    {
        $this->scenarioTheFlagIsClearedWithAutoCommitOff();
    }

    public function testTheRunnerClearsAStaleFlag(): void
    {
        $this->scenarioTheRunnerClearsAStaleFlag();
    }
}
