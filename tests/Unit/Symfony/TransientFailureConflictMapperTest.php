<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\Symfony\Conflict\TransientFailureConflictMapper;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TransientFailureConflictMapperTest extends TestCase
{
    public function testTransientFailuresAreConflicts(): void
    {
        $mapper = new TransientFailureConflictMapper();

        self::assertTrue($mapper->isConflict(new RuntimeException('wrapped', 0, new TransientStorageFailure())));
        self::assertTrue($mapper->isConflict(FakeDriverException::serializationFailure()));
        self::assertFalse($mapper->isConflict(new RuntimeException('bug')));
    }

    public function testALostSavepointWithoutADeadlockIsAConflictButUniqueAndConnectionFailuresAreNot(): void
    {
        $mapper = new TransientFailureConflictMapper();

        self::assertTrue($mapper->isConflict(new DriverException(new FakeDriverException('SAVEPOINT DOCTRINE_2 does not exist', '42000', 1305), null)));
        self::assertFalse($mapper->isConflict(new UniqueConstraintViolationException(new FakeDriverException('Duplicate entry', '23000', 1062), null)));
        self::assertFalse($mapper->isConflict(new ConnectionLost(new FakeDriverException('MySQL server has gone away', 'HY000', 2006), null)));
    }
}
