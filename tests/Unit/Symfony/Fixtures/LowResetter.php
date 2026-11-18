<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures;

use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;

/**
 * Autoconfigured resetter without a priority (runs after HighResetter).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class LowResetter implements ResetBetweenAttemptsInterface
{
    public function __construct(private ReplayLog $log)
    {
    }

    public function resetBetweenAttempts(): void
    {
        $this->log->entries[] = 'low';
    }
}
