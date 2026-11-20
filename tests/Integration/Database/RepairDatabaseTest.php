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
    public function testAReplayKeepsTheHostsEntities(string $server): void
    {
        $this->boot($server);
        $this->scenarioAReplayKeepsTheHostsEntities();
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

    #[DataProvider('allServers')]
    public function testAFlushedHostEntityIsReloaded(string $server): void
    {
        $this->boot($server);
        $this->scenarioAFlushedHostEntityIsReloaded();
    }

    #[DataProvider('allServers')]
    public function testAFlushedIncrementIsNotCountedTwice(string $server): void
    {
        $this->boot($server);
        $this->scenarioAFlushedIncrementIsNotCountedTwice();
    }

    #[DataProvider('allServers')]
    public function testAVersionedHostEntityKeepsItsStoredVersion(string $server): void
    {
        $this->boot($server);
        $this->scenarioAVersionedHostEntityKeepsItsStoredVersion();
    }

    #[DataProvider('allServers')]
    public function testAFlushedRemovalIsUndone(string $server): void
    {
        $this->boot($server);
        $this->scenarioAFlushedRemovalIsUndone();
    }

    #[DataProvider('allServers')]
    public function testARolledBackOrphanRemovalIsCancelledRemoveElement(string $server): void
    {
        $this->boot($server);
        $this->scenarioARolledBackOrphanRemovalIsCancelled(false);
    }

    #[DataProvider('allServers')]
    public function testARolledBackOrphanRemovalIsCancelledClear(string $server): void
    {
        $this->boot($server);
        $this->scenarioARolledBackOrphanRemovalIsCancelled(true);
    }

    #[DataProvider('allServers')]
    public function testAnUnflushedRemovalIsCancelled(string $server): void
    {
        $this->boot($server);
        $this->scenarioAnUnflushedRemovalIsCancelled();
    }

    #[DataProvider('allServers')]
    public function testAnUnrestorableHostEntityStillRestoresTheRest(string $server): void
    {
        $this->boot($server);
        $this->scenarioAnUnrestorableHostEntityStillRestoresTheRest(false);
    }

    #[DataProvider('allServers')]
    public function testAnUnrestorableHostEntityStillRestoresTheRestWithAnInitialisedCollection(string $server): void
    {
        $this->boot($server);
        $this->scenarioAnUnrestorableHostEntityStillRestoresTheRest(true);
    }

    #[DataProvider('allServers')]
    public function testAToOneToABroughtEntityIsReloaded(string $server): void
    {
        $this->boot($server);
        $this->scenarioAToOneToABroughtEntityIsReloaded();
    }

    #[DataProvider('allServers')]
    public function testAGoneRowStopsTheReplay(string $server): void
    {
        $this->boot($server);
        $this->scenarioAGoneRowStopsTheReplay();
    }

    #[DataProvider('allServers')]
    public function testAHostRunnerFlushIsReloadedWrite(string $server): void
    {
        $this->boot($server);
        $this->scenarioAHostRunnerFlushIsReloaded(false);
    }

    #[DataProvider('allServers')]
    public function testAHostRunnerFlushIsReloadedIncrement(string $server): void
    {
        $this->boot($server);
        $this->scenarioAHostRunnerFlushIsReloaded(true);
    }

    #[DataProvider('allServers')]
    public function testAReadonlyVersionedParentIsReloaded(string $server): void
    {
        $this->boot($server);
        $this->scenarioAReadonlyVersionedParentIsReloaded();
    }

    #[DataProvider('allServers')]
    public function testACascadeDetachFromABroughtEntityStopsAtTheHost(string $server): void
    {
        $this->boot($server);
        $this->scenarioACascadeDetachFromABroughtEntityStopsAtTheHost();
    }

    #[DataProvider('allServers')]
    public function testANeverPersistedChildIsNotInserted(string $server): void
    {
        $this->boot($server);
        $this->scenarioANeverPersistedChildIsNotInserted(false);
    }

    #[DataProvider('allServers')]
    public function testANeverPersistedChildIsNotInsertedWithAnInitialisedCollection(string $server): void
    {
        $this->boot($server);
        $this->scenarioANeverPersistedChildIsNotInserted(true);
    }

    #[DataProvider('allServers')]
    public function testAMoveToALoadedParentIsUndone(string $server): void
    {
        $this->boot($server);
        $this->scenarioAMoveToALoadedParentIsUndone();
    }

    #[DataProvider('allServers')]
    public function testADeletedReadonlyParentIsWritableAgain(string $server): void
    {
        $this->boot($server);
        $this->scenarioADeletedReadonlyParentIsWritableAgain();
    }

    #[DataProvider('allServers')]
    public function testAFlushedOrphanRemovalIsUndone(string $server): void
    {
        $this->boot($server);
        $this->scenarioAFlushedOrphanRemovalIsUndone();
    }

    #[DataProvider('allServers')]
    public function testAStaleUntouchedHostEntityDoesNotStopTheReplay(string $server): void
    {
        $this->boot($server);
        $this->scenarioAStaleUntouchedHostEntityDoesNotStopTheReplay();
    }

    #[DataProvider('allServers')]
    public function testARemovalOfTheAttemptsOwnEntityIsForgotten(string $server): void
    {
        $this->boot($server);
        $this->scenarioARemovalOfTheAttemptsOwnEntityIsForgotten();
    }

    #[DataProvider('allServers')]
    public function testAComputedChangeIsStillUndone(string $server): void
    {
        $this->boot($server);
        $this->scenarioAComputedChangeIsStillUndone();
    }

    #[DataProvider('allServers')]
    public function testARemovedOwnEntityIsWritableInTheReplay(string $server): void
    {
        $this->boot($server);
        $this->scenarioARemovedOwnEntityIsWritableInTheReplay();
    }

    #[DataProvider('allServers')]
    public function testARemovedChildOfAHostCollectionIsWritableInTheReplay(string $server): void
    {
        $this->boot($server);
        $this->scenarioARemovedChildOfAHostCollectionIsWritableInTheReplay();
    }

    #[DataProvider('allServers')]
    public function testAnUnflushedChangeOfAParentAndChildIsUndone(string $server): void
    {
        $this->boot($server);
        $this->scenarioAnUnflushedChangeOfAParentAndChildIsUndone();
    }

    #[DataProvider('allServers')]
    public function testAnUnflushedSwitchToALoadedParentIsUndone(string $server): void
    {
        $this->boot($server);
        $this->scenarioAnUnflushedSwitchToALoadedParentIsUndone();
    }

    #[DataProvider('allServers')]
    public function testADeletedGeneratedIdEntityIsWritableAgain(string $server): void
    {
        $this->boot($server);
        $this->scenarioADeletedGeneratedIdEntityIsWritableAgain();
    }

    #[DataProvider('allServers')]
    public function testAReplayAfterAnInPlaceResetGoesAhead(string $server): void
    {
        $this->boot($server);
        $this->scenarioAReplayAfterAnInPlaceResetGoesAhead();
    }

    #[DataProvider('allServers')]
    public function testAReplayAfterTheAttemptClearedTheManagerGoesAhead(string $server): void
    {
        $this->boot($server);
        $this->scenarioAReplayAfterTheAttemptClearedTheManagerGoesAhead();
    }
}
