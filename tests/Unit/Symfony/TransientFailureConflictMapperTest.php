<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

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
}
