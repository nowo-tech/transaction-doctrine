<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\HostManagerScenarios;

use function getmypid;
use function is_file;
use function sys_get_temp_dir;
use function unlink;

/**
 * The forHostEntityManager() scenarios (audit TA3-1 to TA3-3) on a real ORM over a SQLite file, so the unit suite
 * covers them; the same scenarios run on MySQL, MariaDB and PostgreSQL in HostEntityManagerDatabaseTest.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class HostEntityManagerSqliteTest extends DatabaseTestCase
{
    use HostManagerScenarios;

    private string $file = '';

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/nowo-td-host-' . getmypid() . '.sqlite';
        $this->bootUrl('sqlite:///' . $this->file);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testADetachNeverCascadesIntoTheHostsEntity(): void
    {
        $this->scenarioADetachNeverCascadesIntoTheHostsEntity();
    }

    public function testARolledBackChildLeavesTheHostsCollection(): void
    {
        $this->scenarioARolledBackChildLeavesTheHostsCollection();
    }

    public function testARolledBackChildLeavesAnUninitializedCollection(): void
    {
        $this->scenarioARolledBackChildLeavesAnUninitializedCollection();
    }

    public function testTheHostsOwnCollectionChangeStays(): void
    {
        $this->scenarioTheHostsOwnCollectionChangeStays();
    }

    public function testAToOneGetsItsOriginalValueBack(): void
    {
        $this->scenarioAToOneGetsItsOriginalValueBack();
    }

    public function testNewHostEntities(): void
    {
        $this->scenarioNewHostEntities();
    }

    public function testAFieldTheRunChangedStaysWithANotice(): void
    {
        $this->scenarioAFieldTheRunChangedStaysWithANotice();
    }
}
