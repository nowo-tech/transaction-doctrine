<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
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
        $observed->expects($this->never())->method('getUnitOfWork');
        $observer = new AttemptObserver($observed, []);

        $observer->onFlush(new OnFlushEventArgs($this->createMock(EntityManagerInterface::class)));

        self::assertFalse($observer->flushed);
        self::assertSame([], $observer->deleted);
    }
}
