<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Nowo\TransactionDoctrine\WriteUnit\AttemptObserver;
use PHPUnit\Framework\TestCase;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class AttemptObserverTest extends TestCase
{
    /** DoctrineBundle shares an event manager between the managers of a connection: another manager's flush is ignored. */
    public function testAFlushOfAnotherManagerIsIgnored(): void
    {
        $observed = $this->createMock(EntityManagerInterface::class);
        // Read once, when the observer records the unit of work it watches (1.8.1); never for another manager's flush.
        $observed->expects($this->once())->method('getUnitOfWork')->willReturn($this->createStub(UnitOfWork::class));
        $observer = new AttemptObserver($observed, []);

        $observer->onFlush(new OnFlushEventArgs($this->createMock(EntityManagerInterface::class)));

        self::assertFalse($observer->flushed);
        self::assertSame([], $observer->deleted);
    }
}
