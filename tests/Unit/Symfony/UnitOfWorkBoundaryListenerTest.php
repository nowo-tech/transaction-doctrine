<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\TransactionDoctrine\Symfony\EventListener\UnitOfWorkBoundaryListener;
use PHPUnit\Framework\TestCase;
use stdClass;
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
}
