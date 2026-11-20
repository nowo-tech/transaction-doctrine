<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit;

use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\OutermostTransactions;
use Nowo\TransactionDoctrine\WriteUnit\TransactionMark;
use PHPUnit\Framework\TestCase;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class OutermostTransactionsTest extends TestCase
{
    /** Audit WD-5: each transaction's outcome lives in its marks; however many transactions follow, it is never forgotten. */
    public function testAnOutcomeIsNeverForgotten(): void
    {
        $ledger       = new CommitLedger();
        $transactions = new OutermostTransactions();
        self::assertNull($transactions->current());
        $transactions->committed(); // nothing open: ignored
        self::assertNull($transactions->current());

        $transactions->begin();
        $open = $transactions->current();
        self::assertNotNull($open);
        $mark = new TransactionMark($open);
        self::assertFalse($mark->isAutoCommit());
        self::assertNull($ledger->outcome($mark));
        self::assertFalse($ledger->ended($mark), 'still open');
        $transactions->rolledBack();
        self::assertFalse($ledger->outcome($mark));
        self::assertTrue($ledger->ended($mark));
        self::assertNull($transactions->current());

        for ($i = 0; $i < 1000; ++$i) {
            $transactions->begin();
            $transactions->committed();
        }

        self::assertFalse($ledger->outcome($mark), 'not forgotten');
        $open->end(true); // only the first end counts
        self::assertFalse($ledger->outcome($mark));
    }

    /** Audit WD-7: a connection lost during COMMIT ends the transaction with an unknown outcome, unlike an open one. */
    public function testALostCommitIsUnknownButEnded(): void
    {
        $ledger       = new CommitLedger();
        $transactions = new OutermostTransactions();
        $transactions->begin();
        $mark = new TransactionMark($transactions->current());
        $transactions->lost();
        self::assertNull($ledger->outcome($mark));
        self::assertTrue($ledger->ended($mark));

        $transactions->begin();
        $first = $transactions->current();
        $transactions->begin(); // a BEGIN over an unseen end: the earlier one is over
        self::assertNotNull($first);
        self::assertFalse($first->committed());
        self::assertTrue($first->hasEnded());

        $auto = new TransactionMark();
        self::assertTrue($auto->isAutoCommit());
        self::assertTrue($ledger->outcome($auto));
        self::assertTrue($ledger->ended($auto));
    }
}
