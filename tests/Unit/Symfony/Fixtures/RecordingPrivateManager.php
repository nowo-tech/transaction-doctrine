<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Stands for a private entity manager (tagged `nowo_transaction_doctrine.private_entity_manager`): live() is a unit
 * using it, reset() is the boundary dropping it. "Kept" = still initialised (no boundary dropped it).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RecordingPrivateManager implements ResetInterface
{
    public int $resets = 0;

    private bool $initialized = false;

    public function live(): void
    {
        $this->initialized = true;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function reset(): void
    {
        ++$this->resets;
        $this->initialized = false;
    }
}
