<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\EventListener;

use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\Symfony\Boundary\UnitOfWorkFrames;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Unit-of-work boundaries (1.9): resets the HostFlushProbe and every private entity manager (services tagged
 * `nowo_transaction_doctrine.private_entity_manager`) at the start and end of the main request, of a Messenger message
 * and of the outermost console command. Works with the kernel reset off (FrankenPHP worker): nothing waits for
 * `kernel.reset`.
 *
 * Since 1.9.1 the Symfony bundle gives it the {@see UnitOfWorkFrames} tracker, which judges each boundary event
 * (OUTERMOST units only): a command, a request or a worker run in-process inside another unit never resets the state
 * of the unit that runs it; an aborted Messenger run, a message skipped with `shouldHandle(false)`, a nested main
 * request, and a `console.error` / `kernel.exception` / `WorkerMessageReceivedEvent` listener that throws never leave
 * the process "inside" a unit for good (audits NX CR1–CR3, OB-M1, OB-L1–L3, ported from the bundles' trackers).
 *
 * Without the tracker (built by hand, as in 1.9.0) its only state is the console command depth.
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
     * @param UnitOfWorkFrames|null $frames The unit tracker (1.9.1; null: the 1.9.0 command-depth counter)
     */
    public function __construct(
        private readonly iterable $privateManagers = [],
        private readonly ?HostFlushProbe $probe = null,
        private readonly ?UnitOfWorkFrames $frames = null,
    ) {
    }

    /** kernel.request / kernel.terminate of the main request, Messenger received / handled / failed. */
    public function onBoundary(object $event): void
    {
        if ($event instanceof KernelEvent && !$event->isMainRequest()) {
            return;
        }

        if (!$this->frames instanceof UnitOfWorkFrames || $this->frames->isOutermost($event)) {
            $this->reset();
        }
    }

    /** console.command: only the outermost command starts a unit of work. */
    public function onCommand(?object $event = null): void
    {
        if ($this->frames instanceof UnitOfWorkFrames && $event !== null) {
            $this->onBoundary($event);

            return;
        }

        if ($this->commandDepth++ === 0) { // @igor-ignore — nesting depth of the running command (no tracker)
            $this->reset();
        }
    }

    /** console.terminate: the outermost command ends its unit of work. */
    public function onCommandTerminate(?object $event = null): void
    {
        if ($this->frames instanceof UnitOfWorkFrames && $event !== null) {
            $this->onBoundary($event);

            return;
        }

        if ($this->commandDepth > 0 && --$this->commandDepth === 0) { // @igor-ignore — nesting depth of the running command (no tracker)
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
