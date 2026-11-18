<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Doctrine\DBAL\Exception\DriverException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
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

    public function testACommitSinceTheMarkMakesAnyConflictCommitted(): void
    {
        $ledger = new CommitLedger();
        $ledger->record();
        $mark     = new CommitMark($ledger);
        $conflict = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, FakeDriverException::deadlock());

        self::assertSame(1, $mark->count);
        self::assertFalse($mark->committedSince(), 'A commit before the mark does not count.');
        self::assertSame(ConflictCode::RetrySafe, $this->code($conflict, $mark));
        self::assertSame(ConflictCode::RetrySafe, $this->code($conflict));

        $ledger->record();

        self::assertTrue($mark->committedSince());
        self::assertSame(ConflictCode::Committed, $this->code($conflict, $mark));
        self::assertSame(ConflictCode::Committed, $this->code(FakeDriverException::deadlock(), $mark));
        self::assertSame(ConflictCode::RetrySafe, $this->code($conflict), 'Without the mark the failure alone decides.');
    }

    public function testForRequestReadsTheRequestsMark(): void
    {
        $ledger   = new CommitLedger();
        $conflict = new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, FakeDriverException::deadlock());
        $request  = Request::create('/x', 'POST');

        self::assertSame(ConflictCode::RetrySafe, $this->codeFor($request, $conflict), 'No mark.');

        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new stdClass());
        $ledger->record();
        self::assertSame(ConflictCode::RetrySafe, $this->codeFor($request, $conflict), 'Anything but a CommitMark is ignored.');

        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($ledger));
        self::assertSame(ConflictCode::RetrySafe, $this->codeFor($request, $conflict));

        $ledger->record();
        self::assertSame(ConflictCode::Committed, $this->codeFor($request, $conflict));
        self::assertSame(ConflictCode::Committed, $this->codeFor(Request::create('/y'), new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock(), true)));
    }

    /** @phpstan-impure the answer depends on the ledger, which moves between calls */
    private function code(Throwable $failure, ?CommitMark $since = null): ConflictCode
    {
        return ConflictCode::forFailure($failure, $since);
    }

    /** @phpstan-impure the answer depends on the ledger, which moves between calls */
    private function codeFor(Request $request, Throwable $failure): ConflictCode
    {
        return ConflictCode::forRequest($request, $failure);
    }
}
