<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures;

use Nowo\TransactionDoctrine\WriteUnit\WriteUnitRetryEvent;

/**
 * Records what ran between write-unit attempts in the kernel tests (resetters by name, then the retry event).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ReplayLog
{
    /** @var list<string> */
    public array $entries = [];

    public function onRetry(WriteUnitRetryEvent $event): void
    {
        $this->entries[] = 'event ' . $event->attempt . ' ' . $event->unit . ' ' . $event->kind->value;
    }
}
