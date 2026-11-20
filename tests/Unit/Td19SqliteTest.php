<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\EpochScenarios;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\HostFlushProbeScenarios;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\PrivateManagerScenarios;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;

use function getmypid;
use function is_file;
use function sys_get_temp_dir;
use function unlink;

/**
 * The 1.9 scenarios (outermost-commit epoch, host-flush probe, private entity manager kit) on a SQLite file, so the
 * unit suite covers them; Td19DatabaseTest runs them on MySQL, MariaDB and PostgreSQL.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class Td19SqliteTest extends DatabaseTestCase
{
    use EpochScenarios;
    use HostFlushProbeScenarios;
    use PrivateManagerScenarios;

    private string $file = '';

    protected function setUp(): void
    {
        $this->file   = sys_get_temp_dir() . '/nowo-td-19-' . getmypid() . '.sqlite';
        $this->ledger = new CommitLedger();
        $this->bootUrl('sqlite:///' . $this->file);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testAMarkFollowsItsOwnTransaction(): void
    {
        $this->scenarioAMarkFollowsItsOwnTransaction();
    }

    public function testARolledBackHostTransactionIsNotAPhantom(): void
    {
        $this->scenarioARolledBackHostTransactionIsNotAPhantom();
    }

    public function testAClosedConnectionRollsItsTransactionBack(): void
    {
        $this->scenarioAClosedConnectionRollsItsTransactionBack();
    }

    public function testAFailedStatementBeforeCommit(): void
    {
        $this->scenarioAFailedStatementBeforeCommit();
    }

    public function testAnUntrackedTransactionCannotBeMarked(): void
    {
        $this->scenarioAnUntrackedTransactionCannotBeMarked();
    }

    public function testTheProbeReportsTheHostsPendingChangesOnce(): void
    {
        $this->scenarioTheProbeReportsTheHostsPendingChangesOnce();
    }

    public function testTheProbeSeesRemovalsAndToOneChanges(): void
    {
        $this->scenarioTheProbeSeesRemovalsAndToOneChanges();
        $this->scenarioTheProbeMatchesWhatTheFlushWrites();
    }

    public function testTheProbeComparesEnumsAsTheFlushDoes(): void
    {
        $this->scenarioTheProbeComparesEnumsAsTheFlushDoes();
    }

    public function testTheProbeNeverBreaksTheWrite(): void
    {
        $this->scenarioTheProbeNeverBreaksTheWrite();
    }

    public function testThePrivateManagerNeverFlushesTheHostsWork(): void
    {
        $this->scenarioThePrivateManagerNeverFlushesTheHostsWork();
    }

    public function testAClosedPrivateManagerIsReplaced(): void
    {
        $this->scenarioAClosedPrivateManagerIsReplaced();
    }

    public function testAWrongSourceIsRefused(): void
    {
        $this->scenarioAWrongSourceIsRefused();
    }

    public function testTheSourcesFiltersAreMirrored(): void
    {
        $this->scenarioTheSourcesFiltersAreMirrored();
    }

    public function testTheRegistryResolvesTheBundlesManager(): void
    {
        $this->scenarioTheRegistryResolvesTheBundlesManager();
    }

    public function testARollbackMakesThePrivateManagerStale(): void
    {
        $this->scenarioARollbackMakesThePrivateManagerStale();
        $this->scenarioAClosedConnectionMakesThePrivateManagerStale();
        $this->scenarioAFailedHostStatementBeforeCommit();
    }

    public function testABoundaryNeverFails(): void
    {
        $this->scenarioABoundaryNeverFails();
    }
}
