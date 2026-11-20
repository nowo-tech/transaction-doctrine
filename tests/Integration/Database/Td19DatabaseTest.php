<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\EpochScenarios;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\HostFlushProbeScenarios;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\PrivateManagerScenarios;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The 1.9 scenarios on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (Td19SqliteTest runs them on SQLite).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class Td19DatabaseTest extends DatabaseTestCase
{
    use EpochScenarios;
    use HostFlushProbeScenarios;
    use PrivateManagerScenarios;

    #[DataProvider('allServers')]
    public function testAMarkFollowsItsOwnTransaction(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioAMarkFollowsItsOwnTransaction();
    }

    #[DataProvider('allServers')]
    public function testARolledBackHostTransactionIsNotAPhantom(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioARolledBackHostTransactionIsNotAPhantom();
    }

    #[DataProvider('allServers')]
    public function testAClosedConnectionRollsItsTransactionBack(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioAClosedConnectionRollsItsTransactionBack();
    }

    #[DataProvider('allServers')]
    public function testAnUntrackedTransactionCannotBeMarked(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioAnUntrackedTransactionCannotBeMarked();
    }

    #[DataProvider('allServers')]
    public function testTheProbeReportsTheHostsPendingChangesOnce(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioTheProbeReportsTheHostsPendingChangesOnce();
    }

    #[DataProvider('allServers')]
    public function testTheProbeSeesRemovalsAndToOneChanges(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioTheProbeSeesRemovalsAndToOneChanges();
    }

    #[DataProvider('allServers')]
    public function testThePrivateManagerNeverFlushesTheHostsWork(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioThePrivateManagerNeverFlushesTheHostsWork();
    }

    #[DataProvider('allServers')]
    public function testAClosedPrivateManagerIsReplaced(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioAClosedPrivateManagerIsReplaced();
    }

    #[DataProvider('allServers')]
    public function testAWrongSourceIsRefused(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioAWrongSourceIsRefused();
    }

    #[DataProvider('allServers')]
    public function testTheSourcesFiltersAreMirrored(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioTheSourcesFiltersAreMirrored();
    }

    #[DataProvider('allServers')]
    public function testTheRegistryResolvesTheBundlesManager(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioTheRegistryResolvesTheBundlesManager();
    }

    #[DataProvider('allServers')]
    public function testARollbackMakesThePrivateManagerStale(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioARollbackMakesThePrivateManagerStale();
    }

    #[DataProvider('allServers')]
    public function testABoundaryNeverFails(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->scenarioABoundaryNeverFails();
    }
}
