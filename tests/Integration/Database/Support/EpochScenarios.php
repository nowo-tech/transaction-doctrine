<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use LogicException;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;

/**
 * 1.9: the per-connection outermost-commit epoch (CommitLedger::mark() / outcome()). Shared by the real-database suite
 * and the unit suite (SQLite file). The test sets $this->ledger before booting, so the connection is tracked.
 *
 * @phpstan-require-extends DatabaseTestCase
 */
trait EpochScenarios
{
    private function trackedLedger(): CommitLedger
    {
        self::assertInstanceOf(CommitLedger::class, $this->ledger);

        return $this->ledger;
    }

    /** A mark follows the transaction it was taken in: open, then committed; another one rolled back. */
    private function scenarioAMarkFollowsItsOwnTransaction(): void
    {
        $ledger = $this->trackedLedger();
        $before = $ledger->mark($this->connection);
        self::assertTrue($before->isAutoCommit());
        self::assertTrue($ledger->outcome($before));

        $this->connection->beginTransaction();
        $this->connection->insert('ddrb_counter', ['id' => 10, 'counter_value' => 1]);
        $committed = $ledger->mark($this->connection);
        self::assertFalse($committed->isAutoCommit());
        self::assertNull($ledger->outcome($committed), 'still open');
        $this->connection->commit();
        self::assertTrue($ledger->outcome($committed));

        $this->connection->beginTransaction();
        $this->connection->insert('ddrb_counter', ['id' => 11, 'counter_value' => 1]);
        $rolledBack = $ledger->mark($this->connection);
        $this->connection->rollBack();
        self::assertFalse($ledger->outcome($rolledBack));
        self::assertTrue($ledger->outcome($committed), 'a later transaction never changes an earlier outcome');
    }

    /**
     * The phantom case (Uptime's audit-trail outbox, UserKit's savepoint case): a bundle releases its savepoint inside a
     * host transaction and marks it; the host rolls back; a later, unrelated write commits. The ledger count moved (a
     * CommitMark says "committed"), but the marked transaction did not commit.
     */
    private function scenarioARolledBackHostTransactionIsNotAPhantom(): void
    {
        $ledger = $this->trackedLedger();
        $this->connection->beginTransaction(); // the host's
        $watermark = new CommitMark($ledger);
        $this->connection->beginTransaction(); // the bundle's (a savepoint)
        $this->connection->insert('ddrb_counter', ['id' => 12, 'counter_value' => 1]);
        $this->connection->commit(); // savepoint released
        $mark = $ledger->mark($this->connection);
        $this->connection->rollBack(); // the host gives up

        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = counter_value + 1 WHERE id = 1'); // auto-committed
        self::assertTrue($watermark->committedSince(), 'the process-wide count moved');
        self::assertFalse($ledger->outcome($mark), 'but the marked transaction rolled back');
        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 12'));
    }

    /** A connection closed with its transaction open rolls it back (the server does), and a reconnect starts afresh. */
    private function scenarioAClosedConnectionRollsItsTransactionBack(): void
    {
        $ledger = $this->trackedLedger();
        $this->connection->beginTransaction();
        $mark = $ledger->mark($this->connection);
        $this->connection->close();
        self::assertFalse($ledger->outcome($mark));

        $this->connection->beginTransaction();
        $again = $ledger->mark($this->connection);
        $this->connection->commit();
        self::assertTrue($ledger->outcome($again));
        self::assertFalse($ledger->outcome($mark));
    }

    /** A ledger that does not track the connection refuses to mark its transaction. */
    private function scenarioAnUntrackedTransactionCannotBeMarked(): void
    {
        $this->connection->beginTransaction();

        try {
            (new CommitLedger())->mark($this->connection);
            self::fail('No LogicException.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('CommitTrackingMiddleware', $exception->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }
}
