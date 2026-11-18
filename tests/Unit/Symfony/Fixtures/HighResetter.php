<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures;

use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Autoconfigured resetter with priority 10 (registered after LowResetter, called before it).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsTaggedItem(priority: 10)]
final readonly class HighResetter implements ResetBetweenAttemptsInterface
{
    public function __construct(private ReplayLog $log)
    {
    }

    public function resetBetweenAttempts(): void
    {
        $this->log->entries[] = 'high';
    }
}
