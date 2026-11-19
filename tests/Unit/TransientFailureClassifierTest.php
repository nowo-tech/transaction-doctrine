<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransientFailureClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, TransientFailureKind|null}>
     */
    public static function failures(): iterable
    {
        yield 'MySQL deadlock 1213' => [new DeadlockException(self::driver(1213, '40001'), null), TransientFailureKind::Deadlock];
        yield 'PostgreSQL deadlock 40P01' => [new DeadlockException(self::driver(7, '40P01'), null), TransientFailureKind::Deadlock];
        yield 'PostgreSQL serialization 40001' => [new DeadlockException(self::driver(7, '40001'), null), TransientFailureKind::SerializationFailure];
        yield 'DeadlockException without codes' => [new DeadlockException(self::driver(0, null), null), TransientFailureKind::Deadlock];
        yield 'LockWaitTimeoutException' => [new LockWaitTimeoutException(self::driver(1205, 'HY000'), null), TransientFailureKind::LockWaitTimeout];
        yield 'raw driver 1205' => [self::driver(1205, 'HY000'), TransientFailureKind::LockWaitTimeout];
        yield 'raw driver 1213' => [self::driver(1213, '40001'), TransientFailureKind::Deadlock];
        yield 'lost savepoint 1305 alone (implicit commit)' => [self::lostSavepoint('SAVEPOINT DOCTRINE_2 does not exist'), null];
        yield 'lost savepoint 1305 over a unique violation' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', new DriverException(self::driver(1062, '23000'), null)), null), null];
        yield 'PostgreSQL lock_timeout 55P03' => [new DriverException(self::driver(7, '55P03'), null), TransientFailureKind::LockWaitTimeout];
        yield 'MariaDB snapshot conflict 1020' => [new DriverException(self::driver(1020, 'HY000'), null), TransientFailureKind::SerializationFailure];
        yield '1305 chained to its deadlock' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', new DeadlockException(self::driver(1213, '40001'), null)), null), TransientFailureKind::Deadlock];
        yield 'other RetryableException' => [new class('retry') extends Exception implements RetryableException {}, TransientFailureKind::Retryable];
        yield 'application code 1213' => [new RuntimeException('not a deadlock', 1213), null];
        yield 'wrapped deadlock' => [new RuntimeException('wrapper', 0, new DeadlockException(self::driver(1213, '40001'), null)), TransientFailureKind::Deadlock];
        yield 'constraint violation' => [new DriverException(self::driver(1062, '23000'), null), null];
        yield 'PDO errorInfo 1213' => [self::pdo('HY000', [null, 1213]), TransientFailureKind::Deadlock];
        yield 'PDO errorInfo 1205' => [self::pdo('HY000', ['HY000', 1205]), TransientFailureKind::LockWaitTimeout];
        yield 'PDO SQLSTATE 40001 without errorInfo' => [self::pdo('40001', null), TransientFailureKind::SerializationFailure];
        yield 'PDO other' => [self::pdo('23000', ['23000', 1062]), null];
        yield 'PostgreSQL unique violation 23505' => [new DriverException(self::driver(7, '23505'), null), null];
        yield 'MySQL unique violation 1062' => [new UniqueConstraintViolationException(self::driver(1062, '23000'), null), null];
        yield 'MySQL server gone away 2006' => [new ConnectionLost(self::driver(2006, 'HY000'), null), null];
        yield 'MySQL lost connection 2013' => [new DriverException(self::driver(2013, 'HY000'), null), null];
        yield 'PostgreSQL connection failure 08006' => [new DriverException(self::driver(7, '08006'), null), null];
        // Audit TA3-5: DBAL 3's Driver\PDO\PDOException (a PDOException and a driver exception, SQLSTATE in getCode()).
        yield 'DBAL 3 PDO driver 40001 with errorInfo' => [new RuntimeException('wrapper', 0, self::pdoDriver('40001', ['40001', 7], null)), TransientFailureKind::SerializationFailure];
        yield 'DBAL 3 PDO driver 40P01 without errorInfo' => [self::pdoDriver('40P01', null, null), TransientFailureKind::Deadlock];
        yield 'DBAL 3 PDO driver without SQLSTATE in its code' => [self::pdoDriver(0, null, '40001'), TransientFailureKind::SerializationFailure];
        yield 'DBAL 3 PDO driver 1213 errorInfo' => [self::pdoDriver('40001', ['40001', 1213], null), TransientFailureKind::Deadlock];
        yield 'DBAL 3 PDO driver unique 23505' => [self::pdoDriver('23505', ['23505', 7], null), null];
        yield 'driver exception with a string code' => [self::stringCodeDriver('40001', '40001'), TransientFailureKind::SerializationFailure];
    }

    /**
     * The static finders (1.7) on a getPrevious() chain; Messenger wrappers are covered by
     * TransientFailureClassifierMessengerTest.
     */
    public function testTheStaticFindersReadTheChain(): void
    {
        $deadlock = new DeadlockException(self::driver(1213, '40001'), null);
        $conflict = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, 1, $deadlock);
        $lost     = new RuntimeException('wrap', 0, self::lostSavepoint('SAVEPOINT DOCTRINE_2 does not exist'));
        $storage  = TransientStorageFailure::fromPrevious(new RuntimeException('bundle runner'));

        self::assertSame($deadlock, TransientFailureClassifier::findConflict(new RuntimeException('a', 0, $deadlock)));
        self::assertSame($conflict, TransientFailureClassifier::findConflict(new RuntimeException('a', 0, $conflict)));
        self::assertSame($conflict, TransientFailureClassifier::findLockConflict(new RuntimeException('a', 0, $conflict)));
        self::assertNull(TransientFailureClassifier::findLockConflict(new RuntimeException('a', 0, $deadlock)));
        self::assertSame($deadlock, TransientFailureClassifier::findTransientFailure(new RuntimeException('a', 0, $deadlock)));
        self::assertSame($storage, TransientFailureClassifier::findTransientFailure($storage));
        self::assertSame($lost, TransientFailureClassifier::findTransientFailure($lost));
        self::assertNull(TransientFailureClassifier::findConflict(new UniqueConstraintViolationException(self::driver(1062, '23000'), null)));
        self::assertNull(TransientFailureClassifier::findConflict(new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', new DriverException(self::driver(1062, '23000'), null)), null)));
        self::assertNull(TransientFailureClassifier::findConflict(new RuntimeException('not a deadlock', 1213)));
    }

    /**
     * Like DBAL 3's Doctrine\DBAL\Driver\PDO\PDOException: extends PDOException and implements the driver exception.
     *
     * @param array<int, mixed>|null $errorInfo
     */
    private static function pdoDriver(int|string $code, ?array $errorInfo, ?string $sqlState): DriverExceptionInterface
    {
        $exception = new class('SQLSTATE error', $sqlState) extends PDOException implements DriverExceptionInterface {
            public function __construct(string $message, private readonly ?string $state)
            {
                parent::__construct($message);
            }

            public function getSQLState(): ?string
            {
                return $this->state;
            }
        };
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($exception, $code);
        $exception->errorInfo = $errorInfo;

        return $exception;
    }

    /** A driver exception (no PDOException) whose getCode() is not an int. */
    private static function stringCodeDriver(string $code, string $sqlState): DriverExceptionInterface
    {
        $exception = self::driver(0, $sqlState);
        (new ReflectionProperty(Exception::class, 'code'))->setValue($exception, $code);

        return $exception;
    }

    /**
     * REQ-RUNTIME-003 rule 6: a lost savepoint with no transient failure in the chain is a commit of unknown outcome
     * (CONFLICT_002); unique violations and lost connections never are, wherever they sit.
     *
     * @return iterable<string, array{Throwable, bool}>
     */
    public static function commitOutcomes(): iterable
    {
        yield 'bare 1305 SAVEPOINT (implicit commit)' => [self::lostSavepoint('SAVEPOINT DOCTRINE_2 does not exist'), true];
        yield 'bare 1305 raw driver' => [self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_3 does not exist'), true];
        yield 'bare 1305 PDO' => [self::pdo('42000', ['42000', 1305], 'SQLSTATE[42000]: 1305 SAVEPOINT DOCTRINE_2 does not exist'), true];
        yield 'PostgreSQL 3B001' => [new DriverException(self::driver(7, '3B001', 'savepoint "DOCTRINE_2" does not exist'), null), true];
        yield 'wrapped bare 1305' => [new RuntimeException('wrap', 0, self::lostSavepoint('SAVEPOINT DOCTRINE_2 does not exist')), true];
        yield '1305 chained to its deadlock' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', new DeadlockException(self::driver(1213, '40001'), null)), null), false];
        yield 'bare 1305 SAVEPOINT es_ES (mysqli form)' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT td_missing no existe'), null), true];
        yield 'bare 1305 SAVEPOINT de_DE (PDO form)' => [new DriverException(self::driver(1305, '42000', 'SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT td_missing existiert nicht'), null), true];
        yield 'bare 1305 SAVEPOINT de_DE (DBAL form)' => [new DriverException(self::driver(1305, '42000', 'An exception occurred while executing a query: SQLSTATE[42000]: Syntax error or access violation: 1305 SAVEPOINT DOCTRINE_2 existiert nicht'), null), true];
        yield '1305 FUNCTION de_DE' => [new DriverException(self::driver(1305, '42000', 'SQLSTATE[42000]: Syntax error or access violation: 1305 FUNCTION ddrb.td_missing_function existiert nicht'), null), false];
        yield '1305 FUNCTION es_ES' => [new DriverException(self::driver(1305, '42000', 'FUNCTION ddrb.td_missing_function no existe'), null), false];
        yield '1305 FUNCTION whose name holds SAVEPOINT' => [new DriverException(self::driver(1305, '42000', 'SQLSTATE[42000]: 1305 FUNCTION ddrb.SAVEPOINT existiert nicht'), null), false];
        yield '1305 missing function' => [new DriverException(self::driver(1305, '42000', 'FUNCTION app.td_fn does not exist'), null), false];
        yield '1305 over a unique violation' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', new UniqueConstraintViolationException(self::driver(1062, '23000'), null)), null), false];
        yield '1305 over a raw unique violation' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', self::driver(1062, '23000')), null), false];
        yield '1305 over a lost connection' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', new ConnectionLost(self::driver(2006, 'HY000'), null)), null), false];
        yield '1305 over a raw lost connection 2013' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', self::driver(2013, 'HY000')), null), false];
        yield '1305 over a PostgreSQL connection failure' => [new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', self::driver(7, '08006')), null), false];
        yield 'application exception naming a savepoint' => [new RuntimeException('SAVEPOINT x does not exist', 1305), false];
        yield 'deadlock' => [new DeadlockException(self::driver(1213, '40001'), null), false];
        yield 'unique violation' => [new UniqueConstraintViolationException(self::driver(1062, '23000'), null), false];
        yield 'lost connection' => [new ConnectionLost(self::driver(2006, 'HY000'), null), false];
        yield 'bug' => [new RuntimeException('bug'), false];
    }

    #[DataProvider('commitOutcomes')]
    public function testIsCommitOutcomeUnknown(Throwable $failure, bool $expected): void
    {
        self::assertSame($expected, (new TransientFailureClassifier())->isCommitOutcomeUnknown($failure));
    }

    #[DataProvider('failures')]
    public function testClassify(Throwable $failure, ?TransientFailureKind $expected): void
    {
        $classifier = new TransientFailureClassifier();

        self::assertSame($expected, $classifier->classify($failure));
        self::assertSame($expected instanceof TransientFailureKind, $classifier->isTransient($failure));
    }

    public function testTransientCauseIsTheFirstTransientExceptionOfTheChain(): void
    {
        $deadlock   = new DeadlockException(self::driver(1213, '40001'), null);
        $lost       = new DriverException(self::driver(1305, '42000', 'SAVEPOINT DOCTRINE_2 does not exist', $deadlock), null);
        $classifier = new TransientFailureClassifier();

        self::assertSame($deadlock, $classifier->transientCause($lost));
        self::assertSame($deadlock, $classifier->transientCause($deadlock));
        self::assertNull($classifier->transientCause(new RuntimeException('bug')));
    }

    private static function driver(int $code, ?string $sqlState, string $message = 'driver error', ?Throwable $previous = null): DriverExceptionInterface
    {
        return new class($message, $code, $previous, $sqlState) extends Exception implements DriverExceptionInterface {
            public function __construct(string $message, int $code, ?Throwable $previous, private readonly ?string $sqlState)
            {
                parent::__construct($message, $code, $previous);
            }

            public function getSQLState(): ?string
            {
                return $this->sqlState;
            }
        };
    }

    private static function lostSavepoint(string $message): DriverException
    {
        return new DriverException(self::driver(1305, '42000', 'SQLSTATE[42000]: Syntax error or access violation: 1305 ' . $message), null);
    }

    /**
     * @param array<int, int|string|null>|null $errorInfo
     */
    private static function pdo(string $sqlState, ?array $errorInfo, string $message = 'pdo'): PDOException
    {
        $pdo = new PDOException($message);
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($pdo, $sqlState);
        $pdo->errorInfo = $errorInfo;

        return $pdo;
    }
}
