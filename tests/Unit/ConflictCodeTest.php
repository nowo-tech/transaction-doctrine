<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Doctrine\DBAL\Exception\DriverException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConflictCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{ConflictCode, string, bool}>
     */
    public static function codes(): iterable
    {
        yield 'retry safe' => [ConflictCode::RetrySafe, ConflictCode::RETRY_SAFE, true];
        yield 'committed' => [ConflictCode::Committed, ConflictCode::COMMITTED, false];
    }

    #[DataProvider('codes')]
    public function testTheValuesAreThePlatformCodes(ConflictCode $code, string $constant, bool $retrySafe): void
    {
        self::assertSame($code, ConflictCode::from($constant));
        self::assertMatchesRegularExpression('/^CONFLICT_00[12]$/', $code->value);
        self::assertSame($retrySafe, $code->retrySafe());
    }

    /**
     * @return iterable<string, array{Throwable, ConflictCode}>
     */
    public static function failures(): iterable
    {
        $deadlock = FakeDriverException::deadlock();

        yield 'conflict, nothing committed' => [new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, $deadlock), ConflictCode::RetrySafe];
        yield 'conflict, committed' => [new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, $deadlock, true), ConflictCode::Committed];
        yield 'wrapped committed conflict' => [new RuntimeException('wrap', 0, new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, $deadlock, true)), ConflictCode::Committed];
        yield 'contract transient failure' => [new TransientStorageFailure(), ConflictCode::RetrySafe];
        yield 'raw deadlock' => [new DriverException($deadlock, null), ConflictCode::RetrySafe];
        yield 'lost savepoint, no deadlock' => [new DriverException(new FakeDriverException('SQLSTATE[42000]: 1305 SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null), ConflictCode::Committed];
        yield 'lost savepoint over a deadlock' => [new DriverException(new FakeDriverException('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305, $deadlock), null), ConflictCode::RetrySafe];
        yield 'conflict wins over a lost savepoint behind it' => [new ConcurrencyConflict(2, TransientFailureKind::Deadlock, 1, new DriverException(new FakeDriverException('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null)), ConflictCode::RetrySafe];
    }

    #[DataProvider('failures')]
    public function testForFailure(Throwable $failure, ConflictCode $expected): void
    {
        self::assertSame($expected, ConflictCode::forFailure($failure));
    }
}
