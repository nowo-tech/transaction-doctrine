<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\Symfony\Boundary\UnitOfWorkFrames;
use Nowo\TransactionDoctrine\Symfony\EventListener\UnitOfWorkBoundaryListener;
use Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures\RecordingPrivateManager;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class UnitOfWorkBoundaryListenerTest extends TestCase
{
    public function testBoundariesResetTheProbeAndThePrivateManagers(): void
    {
        $manager = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $listener = new UnitOfWorkBoundaryListener([$manager], new HostFlushProbe());
        $kernel   = $this->createMock(HttpKernelInterface::class);

        $listener->onBoundary(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(1, $manager->resets);
        $listener->onBoundary(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $manager->resets, 'a sub-request is not a boundary');
        $listener->onBoundary(new stdClass()); // a Messenger event
        self::assertSame(2, $manager->resets);

        $listener->onCommand();
        $listener->onCommand(); // a command running another one
        self::assertSame(3, $manager->resets);
        $listener->onCommandTerminate();
        self::assertSame(3, $manager->resets);
        $listener->onCommandTerminate();
        self::assertSame(4, $manager->resets);
        $listener->onCommandTerminate(); // unbalanced: ignored
        self::assertSame(4, $manager->resets);

        (new UnitOfWorkBoundaryListener())->reset(); // nothing to reset
    }

    public function testWithTheTrackerOnlyOutermostUnitsReset(): void
    {
        $manager  = new RecordingPrivateManager();
        $frames   = new UnitOfWorkFrames();
        $listener = new UnitOfWorkBoundaryListener([$manager], new HostFlushProbe(), $frames);
        $outer    = new ConsoleCommandEvent(new Command('app:outer'), new ArrayInput([]), new NullOutput());
        $inner    = new ConsoleCommandEvent(new Command('app:inner'), new ArrayInput([]), new NullOutput());

        $frames->onCommand($outer);
        $listener->onCommand($outer);
        self::assertSame(1, $manager->resets);
        $frames->onCommand($inner);
        $listener->onCommand($inner);
        self::assertSame(1, $manager->resets, 'a nested command keeps the outer one\'s manager');
        $innerEnd = new ConsoleTerminateEvent($inner->getCommand() ?? new Command('x'), $inner->getInput(), new NullOutput(), 0);
        $frames->onTerminate($innerEnd);
        $listener->onCommandTerminate($innerEnd);
        self::assertSame(1, $manager->resets);
        $outerEnd = new ConsoleTerminateEvent($outer->getCommand() ?? new Command('x'), $outer->getInput(), new NullOutput(), 0);
        $frames->onTerminate($outerEnd);
        $listener->onCommandTerminate($outerEnd);
        self::assertSame(2, $manager->resets);

        // Called without its event (as in 1.9.0): the command-depth counter decides.
        $listener->onCommand();
        $listener->onCommandTerminate();
        self::assertSame(4, $manager->resets);
    }
}
