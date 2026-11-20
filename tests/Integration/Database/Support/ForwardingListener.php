<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Nowo\TransactionDoctrine\PrivateManager\TransactionLevelListenerInterface;

/**
 * Lets a connection be built with the private manager's middleware before the manager (which needs a registry on that
 * connection) exists.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ForwardingListener implements TransactionLevelListenerInterface
{
    public ?TransactionLevelListenerInterface $target = null;

    public function connectionCommitted(): void
    {
        $this->target?->connectionCommitted();
    }

    public function connectionRolledBack(?int $level = 1): void
    {
        $this->target?->connectionRolledBack($level);
    }

    public function savepointReleased(int $level): void
    {
        $this->target?->savepointReleased($level);
    }
}
