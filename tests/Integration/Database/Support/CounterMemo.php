<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;
use RuntimeException;

/**
 * A per-request memo that keeps an entity (as Beacon's OrganizationContext keeps the organisation): read through the
 * registry's current manager, emptied between write-unit attempts.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CounterMemo implements ResetBetweenAttemptsInterface
{
    public int $resets = 0;

    private ?Counter $counter = null;

    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    public function counter(): Counter
    {
        return $this->counter ??= $this->registry->getManager()->find(Counter::class, 1) ?? throw new RuntimeException('No counter 1.');
    }

    public function resetBetweenAttempts(): void
    {
        ++$this->resets;
        $this->counter = null;
    }
}
