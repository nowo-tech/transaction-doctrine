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
        $observed->method('getUnitOfWork')->willReturn($this->createStub(UnitOfWork::class));
        $observer = new AttemptObserver($observed, []);
        // Compared by unit of work (audit VQ6-3): another manager has another one.
        $other = $this->createMock(EntityManagerInterface::class);
        $other->method('getUnitOfWork')->willReturn($this->createStub(UnitOfWork::class));

        $observer->onFlush(new OnFlushEventArgs($other));

        self::assertFalse($observer->flushed);
        self::assertSame([], $observer->deleted);
    }
}
