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
 * The 1.8 replay scenarios (audits VD2-4 / VQ: replays that restore the host's entities instead of clearing them) on a
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

    public function testAReplayKeepsTheHostsEntities(): void
    {
        $this->scenarioAReplayKeepsTheHostsEntities();
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

    public function testAFlushedHostEntityIsReloaded(): void
    {
        $this->scenarioAFlushedHostEntityIsReloaded();
    }

    public function testAFlushedIncrementIsNotCountedTwice(): void
    {
        $this->scenarioAFlushedIncrementIsNotCountedTwice();
    }

    public function testAVersionedHostEntityKeepsItsStoredVersion(): void
    {
        $this->scenarioAVersionedHostEntityKeepsItsStoredVersion();
    }

    public function testAFlushedRemovalIsUndone(): void
    {
        $this->scenarioAFlushedRemovalIsUndone();
    }

    public function testARolledBackOrphanRemovalIsCancelledRemoveElement(): void
    {
        $this->scenarioARolledBackOrphanRemovalIsCancelled(false);
    }

    public function testARolledBackOrphanRemovalIsCancelledClear(): void
    {
        $this->scenarioARolledBackOrphanRemovalIsCancelled(true);
    }

    public function testAPendingOrphanRemovalMakesTheUnitRunOnce(): void
    {
        $this->scenarioAPendingOrphanRemovalMakesTheUnitRunOnce();
    }

    public function testAnUnflushedRemovalIsCancelled(): void
    {
        $this->scenarioAnUnflushedRemovalIsCancelled();
    }

    public function testAnUnrestorableHostEntityStillRestoresTheRest(): void
    {
        $this->scenarioAnUnrestorableHostEntityStillRestoresTheRest(false);
    }

    public function testAnUnrestorableHostEntityStillRestoresTheRestWithAnInitialisedCollection(): void
    {
        $this->scenarioAnUnrestorableHostEntityStillRestoresTheRest(true);
    }

    public function testAToOneToABroughtEntityIsReloaded(): void
    {
        $this->scenarioAToOneToABroughtEntityIsReloaded();
    }

    public function testAGoneRowStopsTheReplay(): void
    {
        $this->scenarioAGoneRowStopsTheReplay();
    }

    public function testAHostRunnerFlushIsReloadedWrite(): void
    {
        $this->scenarioAHostRunnerFlushIsReloaded(false);
    }

    public function testAHostRunnerFlushIsReloadedIncrement(): void
    {
        $this->scenarioAHostRunnerFlushIsReloaded(true);
    }

    public function testAReadonlyVersionedParentIsReloaded(): void
    {
        $this->scenarioAReadonlyVersionedParentIsReloaded();
    }

    public function testACascadeDetachFromABroughtEntityStopsAtTheHost(): void
    {
        $this->scenarioACascadeDetachFromABroughtEntityStopsAtTheHost();
    }

    public function testANeverPersistedChildIsNotInserted(): void
    {
        $this->scenarioANeverPersistedChildIsNotInserted(false);
    }

    public function testANeverPersistedChildIsNotInsertedWithAnInitialisedCollection(): void
    {
        $this->scenarioANeverPersistedChildIsNotInserted(true);
    }

    public function testAMoveToALoadedParentIsUndone(): void
    {
        $this->scenarioAMoveToALoadedParentIsUndone();
    }

    public function testADeletedReadonlyParentIsWritableAgain(): void
    {
        $this->scenarioADeletedReadonlyParentIsWritableAgain();
    }

    public function testAFlushedOrphanRemovalIsUndone(): void
    {
        $this->scenarioAFlushedOrphanRemovalIsUndone();
    }

    public function testAStaleUntouchedHostEntityDoesNotStopTheReplay(): void
    {
        $this->scenarioAStaleUntouchedHostEntityDoesNotStopTheReplay();
    }

    public function testARemovalOfTheAttemptsOwnEntityIsForgotten(): void
    {
        $this->scenarioARemovalOfTheAttemptsOwnEntityIsForgotten();
    }

    public function testAComputedChangeIsStillUndone(): void
    {
        $this->scenarioAComputedChangeIsStillUndone();
    }

    public function testARemovedOwnEntityIsWritableInTheReplay(): void
    {
        $this->scenarioARemovedOwnEntityIsWritableInTheReplay();
    }

    public function testARemovedChildOfAHostCollectionIsWritableInTheReplay(): void
    {
        $this->scenarioARemovedChildOfAHostCollectionIsWritableInTheReplay();
    }

    public function testAnUnflushedChangeOfAParentAndChildIsUndone(): void
    {
        $this->scenarioAnUnflushedChangeOfAParentAndChildIsUndone();
    }

    public function testAnUnflushedSwitchToALoadedParentIsUndone(): void
    {
        $this->scenarioAnUnflushedSwitchToALoadedParentIsUndone();
    }

    public function testADeletedGeneratedIdEntityIsWritableAgain(): void
    {
        $this->scenarioADeletedGeneratedIdEntityIsWritableAgain();
    }

    public function testAReplayAfterAnInPlaceResetGoesAhead(): void
    {
        $this->scenarioAReplayAfterAnInPlaceResetGoesAhead();
    }

    public function testAReplayAfterTheAttemptClearedTheManagerGoesAhead(): void
    {
        $this->scenarioAReplayAfterTheAttemptClearedTheManagerGoesAhead();
    }

    public function testAFlushTimeDeadlockKeepsTheHostEntityInPlace(): void
    {
        $this->scenarioAFlushTimeDeadlockKeepsTheHostEntityInPlace();
    }

    public function testAFlushTimeDeadlockKeepsTheHostEntityInANewManager(): void
    {
        $this->scenarioAFlushTimeDeadlockKeepsTheHostEntityInANewManager();
    }

    public function testAClearedManagerKeepsTheHostEntityWritable(): void
    {
        $this->scenarioAClearedManagerKeepsTheHostEntityWritable();
    }

    public function testADetachedOnlyHostEntityIsWritable(): void
    {
        $this->scenarioADetachedOnlyHostEntityIsWritable();
    }

    public function testAReattachWithoutARowStopsTheReplay(): void
    {
        $this->scenarioAReattachWithoutARowStopsTheReplay();
    }

    public function testAnUninitialisedProxyIsRegisteredAgain(): void
    {
        $this->scenarioAnUninitialisedProxyIsRegisteredAgain();
    }

    public function testABackedEnumIdentifierSurvivesTheReplay(): void
    {
        $this->scenarioABackedEnumIdentifierSurvivesTheReplay();
    }

    public function testADerivedIdentitysOwnerIsReused(): void
    {
        $this->scenarioADerivedIdentitysOwnerIsReused();
    }

    public function testAConcurrentVersionStopsTheReplayOnTouch(): void
    {
        $this->scenarioAConcurrentVersionStopsTheReplay('touch');
    }

    public function testAConcurrentVersionStopsTheReplayOnClear(): void
    {
        $this->scenarioAConcurrentVersionStopsTheReplay('clear');
    }

    public function testAConcurrentVersionStopsTheReplayOnReset(): void
    {
        $this->scenarioAConcurrentVersionStopsTheReplay('reset');
    }

    public function testADecoratedManagersFlushIsReloaded(): void
    {
        $this->scenarioADecoratedManagersFlushIsReloaded();
    }

    public function testADecoratedManagerResetInPlaceThenAFlushedWrite(): void
    {
        $this->scenarioADecoratedManagerResetInPlaceThenAFlushedWrite();
    }

    public function testAHeldProxyIsWrittenAfterAClear(): void
    {
        $this->scenarioAHeldProxyIsWrittenAfterAClear();
    }

    public function testAHeldProxyIsWrittenAfterAReset(): void
    {
        $this->scenarioAHeldProxyIsWrittenAfterAReset();
    }

    public function testAStaleUntouchedEntityDoesNotStopAReattachOnClear(): void
    {
        $this->scenarioAStaleUntouchedEntityDoesNotStopAReattach('clear');
    }

    public function testAStaleUntouchedEntityDoesNotStopAReattachOnReset(): void
    {
        $this->scenarioAStaleUntouchedEntityDoesNotStopAReattach('reset');
    }

    public function testAFailedReloadIsNotAGoneRowOnClear(): void
    {
        $this->scenarioAFailedReloadIsNotAGoneRow('clear');
    }

    public function testAFailedReloadIsNotAGoneRowOnReset(): void
    {
        $this->scenarioAFailedReloadIsNotAGoneRow('reset');
    }

    public function testAReattachRefusesWhatItCannotManageAgain(): void
    {
        $this->scenarioAReattachRefusesWhatItCannotManageAgain();
    }

    public function testAFlushTimeDeleteIsReattached(): void
    {
        $this->scenarioAFlushTimeDeleteIsReattached();
    }
}
