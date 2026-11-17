<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Support;

use Doctrine\DBAL\Driver\AbstractException;

/**
 * A driver error with a vendor code and SQLSTATE (1213 / 40001 deadlock, 1205 / HY000 lock wait timeout…).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class FakeDriverException extends AbstractException
{
    public static function deadlock(): self
    {
        return new self('Deadlock found when trying to get lock', '40001', 1213);
    }

    public static function lockWaitTimeout(): self
    {
        return new self('Lock wait timeout exceeded', 'HY000', 1205);
    }

    public static function serializationFailure(): self
    {
        return new self('could not serialize access', '40001', 0);
    }
}
