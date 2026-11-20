<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\EventListener;

use Nowo\TransactionDoctrine\HostFlushProbe;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Unit-of-work boundaries (1.9): resets the HostFlushProbe and every private entity manager (services tagged
 * `nowo_transaction_doctrine.private_entity_manager`) at the start and end of the main request, of a Messenger message
 * and of the outermost console command. Works with the kernel reset off (FrankenPHP worker): nothing waits for
 * `kernel.reset`.
 *
 * Its only state is the console command depth (a command running another one is one unit of work).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class UnitOfWorkBoundaryListener
{
    private int $commandDepth = 0;

    /**
     * @param iterable<ResetInterface> $privateManagers
     * @param HostFlushProbe|null $probe The shared host-flush probe
     */
    public function __construct(
        private readonly iterable $privateManagers = [],
        private readonly ?HostFlushProbe $probe = null,
    ) {
    }

    /** kernel.request / kernel.terminate of the main request, Messenger received / handled / failed. */
    public function onBoundary(object $event): void
    {
        if ($event instanceof KernelEvent && !$event->isMainRequest()) {
            return;
        }

        $this->reset();
    }

    /** console.command: only the outermost command starts a unit of work. */
    public function onCommand(): void
    {
        if ($this->commandDepth++ === 0) { // @igor-ignore — nesting depth of the running command
            $this->reset();
        }
    }

    /** console.terminate: the outermost command ends its unit of work. */
    public function onCommandTerminate(): void
    {
        if ($this->commandDepth > 0 && --$this->commandDepth === 0) { // @igor-ignore — nesting depth of the running command
            $this->reset();
        }
    }

    /** Resets the probe and the private managers. */
    public function reset(): void
    {
        $this->probe?->reset();
        foreach ($this->privateManagers as $manager) {
            $manager->reset();
        }
    }
}
