<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit;

use Nowo\TransactionDoctrine\WriteUnit\OutermostTransactions;
use PHPUnit\Framework\TestCase;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class OutermostTransactionsTest extends TestCase
{
    public function testOutcomesAreKeptForTheLastTransactionsOnly(): void
    {
        $transactions = new OutermostTransactions();
        self::assertNull($transactions->current());
        $transactions->committed(); // nothing open: ignored
        self::assertNull($transactions->outcome(1));

        $transactions->begin();
        self::assertSame(1, $transactions->current());
        self::assertNull($transactions->outcome(1));
        $transactions->rolledBack();
        self::assertFalse($transactions->outcome(1));
        self::assertNull($transactions->current());

        for ($i = 0; $i < OutermostTransactions::KEEP; ++$i) {
            $transactions->begin();
            $transactions->committed();
        }

        self::assertNull($transactions->outcome(1), 'forgotten: older than KEEP transactions');
        self::assertTrue($transactions->outcome(OutermostTransactions::KEEP + 1));
    }
}
