<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\RetryableException;
use PDOException;
use Throwable;

use function in_array;
use function is_int;
use function is_string;
use function preg_match;
use function str_starts_with;

/**
 * Tells whether a failure is transient (replaying the whole transaction can succeed), walking the previous chain.
 *
 * Only driver-level errors carry a vendor code: an application exception that happens to use code 1213 is not
 * transient. A 1305 "SAVEPOINT DOCTRINE_n does not exist" is never transient on its own: savepoints also vanish on an
 * implicit commit (DDL inside the transaction), after which replaying would repeat committed work. After a nested
 * deadlock the ORM's 1305 carries the deadlock in its previous chain (PHP chains the exception a finally block
 * replaces), and the deadlock is what makes it transient. A connection lost during a commit is not transient either:
 * its outcome is unknown.
 *
 * Such a lone lost savepoint (MySQL / MariaDB 1305 naming a SAVEPOINT, PostgreSQL 3B001) is a commit of unknown outcome
 * instead (isCommitOutcomeUnknown()): conflict code CONFLICT_002, "read again, do not repeat" (REQ-RUNTIME-003 rule
 * 6). A 1305 about a missing FUNCTION or PROCEDURE is not a lost savepoint. Unique (and other constraint) violations
 * and lost connections are never transient nor of unknown outcome, wherever they sit in the chain.
 *
 * The classifier reads error codes (and, for a 1305, whether the server's text names a SAVEPOINT); it never returns or
 * logs an exception message, which can contain SQL and parameters. Stateless: safe as a shared service in a
 * long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransientFailureClassifier
{
    /**
     * @return TransientFailureKind|null The kind of the first transient failure in the chain, or null when there is none
     */
    public function classify(Throwable $throwable): ?TransientFailureKind
    {
        $cause = $this->transientCause($throwable);

        return $cause instanceof Throwable ? $this->classifyOne($cause) : null;
    }

    public function isTransient(Throwable $throwable): bool
    {
        return $this->transientCause($throwable) instanceof Throwable;
    }

    /**
     * Whether the failure is a lost savepoint with no transient failure in its chain (an implicit commit, or a
     * savepoint the server dropped for a reason the chain does not tell): part of the work may already be committed,
     * so a retry could repeat it (REQ-RUNTIME-003 rule 6: CONFLICT_002, no Retry-After). Never true when the chain
     * holds a constraint violation or a lost connection.
     */
    public function isCommitOutcomeUnknown(Throwable $throwable): bool
    {
        if ($this->isTransient($throwable)) {
            return false;
        }

        $lostSavepoint = false;
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            [$vendorCode, $sqlState] = $this->codes($current) ?? [0, null];
            if ($current instanceof ConstraintViolationException || $current instanceof ConnectionException || $this->neverMapped($vendorCode, $sqlState)) {
                return false;
            }

            $lostSavepoint = $lostSavepoint || $this->isLostSavepoint($current, $vendorCode, $sqlState);
        }

        return $lostSavepoint;
    }

    /**
     * @return Throwable|null The first exception of the chain (the throwable itself included) that is a transient failure
     */
    public function transientCause(Throwable $throwable): ?Throwable
    {
        for ($current = $throwable; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($this->classifyOne($current) instanceof TransientFailureKind) {
                return $current;
            }
        }

        return null;
    }

    private function classifyOne(Throwable $throwable): ?TransientFailureKind
    {
        if ($throwable instanceof LockWaitTimeoutException) {
            return TransientFailureKind::LockWaitTimeout;
        }

        $codes = $this->codes($throwable);
        $kind  = $codes === null ? null : $this->fromCodes($codes[0], $codes[1]);

        if ($kind instanceof TransientFailureKind) {
            return $kind;
        }

        if ($throwable instanceof DeadlockException) {
            return TransientFailureKind::Deadlock;
        }

        return $throwable instanceof RetryableException ? TransientFailureKind::Retryable : null;
    }

    /**
     * The vendor code and SQLSTATE of a driver-level error; null for anything else (an application exception's code
     * is never a vendor code).
     *
     * @return array{int, string|null}|null
     */
    private function codes(Throwable $throwable): ?array
    {
        if ($throwable instanceof DriverExceptionInterface) {
            return [$throwable->getCode(), $throwable->getSQLState()];
        }

        if ($throwable instanceof PDOException) {
            $vendorCode = $throwable->errorInfo[1] ?? null;
            $sqlState   = $throwable->errorInfo[0] ?? $throwable->getCode();

            return [is_int($vendorCode) ? $vendorCode : 0, is_string($sqlState) ? $sqlState : null];
        }

        return null;
    }

    /** Constraint violations (SQLSTATE class 23) and lost connections (class 08, MySQL / MariaDB 2006, 2013, 4031). */
    private function neverMapped(int $vendorCode, ?string $sqlState): bool
    {
        return in_array($vendorCode, [2006, 2013, 4031], true)
            || ($sqlState !== null && (str_starts_with($sqlState, '23') || str_starts_with($sqlState, '08')));
    }

    /** PostgreSQL 3B001, or a MySQL / MariaDB 1305 about a SAVEPOINT (1305 also reports a missing FUNCTION / PROCEDURE), in any server language. */
    private function isLostSavepoint(Throwable $throwable, int $vendorCode, ?string $sqlState): bool
    {
        if ($sqlState === '3B001') {
            return true;
        }

        // The object type is never translated ("SAVEPOINT x does not exist", "SAVEPOINT x no existe", "SAVEPOINT x
        // existiert nicht"); match it where the server puts it: first in the text, or right after "1305 " in the
        // PDO / DBAL forms ("SQLSTATE[42000]: …: 1305 SAVEPOINT …"). Audit TA2-2.
        return $vendorCode === 1305 && preg_match('/(?:^|\b1305\s+)SAVEPOINT\s/', $throwable->getMessage()) === 1;
    }

    private function fromCodes(int $vendorCode, ?string $sqlState): ?TransientFailureKind
    {
        return match (true) {
            $vendorCode === 1213, $sqlState === '40P01' => TransientFailureKind::Deadlock,
            $vendorCode === 1205, $sqlState === '55P03' => TransientFailureKind::LockWaitTimeout,
            $sqlState === '40001', $vendorCode === 1020 => TransientFailureKind::SerializationFailure,
            default                                     => null,
        };
    }
}
