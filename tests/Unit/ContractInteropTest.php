<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use LogicException;
use Nowo\TransactionContract\ConcurrencyConflict as ContractConcurrencyConflict;
use Nowo\TransactionContract\ConflictCode as ContractConflictCode;
use Nowo\TransactionContract\LockConflictExceptionInterface as ContractLockConflict;
use Nowo\TransactionContract\TransientFailureKind as ContractTransientFailureKind;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\ConflictCodeResolver;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseContext;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

use function sprintf;

/**
 * transaction-doctrine >= 1.10: the deprecated names are aliases of the framework-free contract types, so catch,
 * instanceof, type declarations and enum identity interoperate in both directions; ConflictCode, which keeps its
 * Doctrine / Symfony helpers, converts to and from the contract enum and every API taking a code accepts both.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ContractInteropTest extends TestCase
{
    public function testTheDeprecatedNamesAreTheContractTypes(): void
    {
        foreach ([
            TransientFailureKind::class           => ContractTransientFailureKind::class,
            ConcurrencyConflict::class            => ContractConcurrencyConflict::class,
            LockConflictExceptionInterface::class => ContractLockConflict::class,
        ] as $deprecated => $contract) {
            self::assertSame($contract, (new ReflectionClass($deprecated))->getName(), sprintf('%s is an alias of %s.', $deprecated, $contract));
        }
    }

    public function testAContractConflictIsCaughtUnderTheDeprecatedNames(): void
    {
        $thrown = new ContractConcurrencyConflict(2, ContractTransientFailureKind::Deadlock, 1, new LogicException('deadlock'), true);

        try {
            throw $thrown;
        } catch (ConcurrencyConflict $caught) {
            self::assertSame($thrown, $caught);
            self::assertSame(TransientFailureKind::Deadlock, $caught->kind);
            self::assertTrue($caught->committed);
        }

        try {
            throw $thrown;
        } catch (LockConflictExceptionInterface $caught) {
            self::assertSame($thrown, $caught);
        }
    }

    public function testAConflictBuiltUnderTheDeprecatedNamesIsCaughtAsTheContractTypes(): void
    {
        $thrown = new ConcurrencyConflict(1, TransientFailureKind::LockWaitTimeout, null, new LogicException('lock wait'));

        try {
            throw $thrown;
        } catch (ContractConcurrencyConflict $caught) {
            self::assertSame($thrown, $caught);
            self::assertSame(ContractTransientFailureKind::LockWaitTimeout, $caught->kind);
        }

        try {
            throw $thrown;
        } catch (ContractLockConflict $caught) {
            self::assertSame($thrown, $caught);
        }
    }

    public function testMarkerInterfacesInteroperateBothWays(): void
    {
        $contractMarked   = new class('busy') extends RuntimeException implements ContractLockConflict {};
        $deprecatedMarked = new class('busy') extends RuntimeException implements LockConflictExceptionInterface {};

        foreach ([$contractMarked, $deprecatedMarked] as $marked) {
            self::assertSame($marked, TransientFailureClassifier::findLockConflict(new RuntimeException('outer', 0, $marked)));
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('instancesUnderEitherName')]
    public function testInstanceofMatchesEitherName(object $value, string $class): void
    {
        self::assertInstanceOf($class, $value);
    }

    /**
     * @return iterable<string, array{object, class-string}>
     */
    public static function instancesUnderEitherName(): iterable
    {
        $contract   = new ContractConcurrencyConflict(1, ContractTransientFailureKind::Deadlock, 1, new LogicException('deadlock'));
        $deprecated = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, new LogicException('deadlock'));
        $marked     = new class('busy') extends RuntimeException implements ContractLockConflict {};
        $oldMarked  = new class('busy') extends RuntimeException implements LockConflictExceptionInterface {};

        yield 'contract conflict, old class name' => [$contract, ConcurrencyConflict::class];
        yield 'contract conflict, old marker name' => [$contract, LockConflictExceptionInterface::class];
        yield 'old-name conflict, contract class' => [$deprecated, ContractConcurrencyConflict::class];
        yield 'old-name conflict, contract marker' => [$deprecated, ContractLockConflict::class];
        yield 'contract-marked exception, old marker' => [$marked, LockConflictExceptionInterface::class];
        yield 'old-marked exception, contract marker' => [$oldMarked, ContractLockConflict::class];
        yield 'contract enum case, old enum name' => [ContractTransientFailureKind::Deadlock, TransientFailureKind::class];
        yield 'old-name enum case, contract enum' => [TransientFailureKind::Retryable, ContractTransientFailureKind::class];
    }

    #[DataProvider('sameValuesUnderEitherName')]
    public function testValuesAreIdenticalUnderEitherName(mixed $contract, mixed $deprecated): void
    {
        self::assertSame($contract, $deprecated);
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function sameValuesUnderEitherName(): iterable
    {
        yield 'kind case' => [ContractTransientFailureKind::Deadlock, TransientFailureKind::Deadlock];
        yield 'kind cases' => [ContractTransientFailureKind::cases(), TransientFailureKind::cases()];
        yield 'kind from value' => [ContractTransientFailureKind::SerializationFailure, TransientFailureKind::from('serialization_failure')];
        yield 'RETRY_SAFE' => [ContractConflictCode::RETRY_SAFE, ConflictCode::RETRY_SAFE];
        yield 'COMMITTED' => [ContractConflictCode::COMMITTED, ConflictCode::COMMITTED];
    }

    public function testConflictCodeConvertsToAndFromTheContractEnum(): void
    {
        foreach (ConflictCode::cases() as $code) {
            $contract = $code->toContract();

            self::assertSame($code->value, $contract->value);
            self::assertSame($code->retrySafe(), $contract->retrySafe());
            self::assertSame($code, ConflictCode::fromContract($contract));
            self::assertSame($code, ConflictCode::fromContract($code));
        }
    }

    public function testTheResolverAnswersTheContractEnumAndTheDeprecatedHelpersAgree(): void
    {
        $retrySafe = new ContractConcurrencyConflict(1, ContractTransientFailureKind::Deadlock, 1, new LogicException('deadlock'));
        $committed = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, new LogicException('deadlock'), true);
        $request   = Request::create('/');

        self::assertSame(ContractConflictCode::RetrySafe, ConflictCodeResolver::forFailure($retrySafe));
        self::assertSame(ContractConflictCode::Committed, ConflictCodeResolver::forFailure($committed));
        self::assertSame(ContractConflictCode::Committed, ConflictCodeResolver::forRequest($request, $committed));
        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($retrySafe));
        self::assertSame(ConflictCode::Committed, ConflictCode::forRequest($request, $committed));
    }

    public function testEveryApiTakingACodeAcceptsBothEnums(): void
    {
        $policy  = new ConflictResponsePolicy(409, 3);
        $failure = new RuntimeException('conflict');

        self::assertSame($policy->headersFor(ConflictCode::RetrySafe), $policy->headersFor(ContractConflictCode::RetrySafe));
        self::assertSame($policy->headersFor(ConflictCode::Committed), $policy->headersFor(ContractConflictCode::Committed));
        self::assertSame(['X-Conflict-Code' => 'CONFLICT_002'], $policy->httpExceptionFor(ContractConflictCode::Committed, 'Reload.', $failure)->getHeaders());

        $fromContract = new ConflictResponseContext(409, null, 'e', 'm', null, $failure, true, ContractConflictCode::Committed);
        self::assertSame(ConflictCode::Committed, $fromContract->code);
        self::assertSame(ContractConflictCode::Committed, $fromContract->contractCode);

        $derived = new ConflictResponseContext(409, 1, 'e', 'm', null, $failure);
        self::assertSame(ConflictCode::RetrySafe, $derived->code);
        self::assertSame(ContractConflictCode::RetrySafe, $derived->contractCode);
    }
}
