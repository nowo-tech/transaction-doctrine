<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * A logger that always throws (audit XA-3: the host-flush probe swallows even that).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ThrowingLogger extends AbstractLogger
{
    public function log($level, string|Stringable $message, array $context = []): void
    {
        throw new RuntimeException('The logger is down.');
    }
}
