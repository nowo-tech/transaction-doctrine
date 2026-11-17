<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Nowo\TransactionDoctrine\Symfony\EventListener\WorkerConnectionHygiene;
use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\Tests\Unit\Support\RecordingRegistry;
use Nowo\TransactionDoctrine\WriteUnit\DoctrineStateRepairer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class WorkerConnectionHygieneTest extends TestCase
{
    public function testAMainRequestResetsAClosedManager(): void
    {
        $registry = $this->registry(false);
        $hygiene  = new WorkerConnectionHygiene(new DoctrineStateRepairer($registry));
        $kernel   = $this->createMock(HttpKernelInterface::class);

        $hygiene->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::SUB_REQUEST));
        self::assertSame([], $registry->resets);
        $hygiene->onRequest(new RequestEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(['default'], $registry->resets);
    }

    public function testADatabaseFailureMarksTheRequestAndTheTerminateRepairs(): void
    {
        $connection = $this->pingedConnection(1);
        $hygiene    = new WorkerConnectionHygiene(new DoctrineStateRepairer(new RecordingRegistry(['default' => $connection], ['default' => $this->manager(true)])));
        $kernel     = $this->createMock(HttpKernelInterface::class);
        $failed     = Request::create('/');
        $plain      = Request::create('/');

        $hygiene->onException(new ExceptionEvent($kernel, $failed, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('x', 0, FakeDriverException::deadlock())));
        $hygiene->onException(new ExceptionEvent($kernel, $plain, HttpKernelInterface::MAIN_REQUEST, new RuntimeException('bug')));

        self::assertTrue($failed->attributes->get(WorkerConnectionHygiene::FAILED_ATTRIBUTE));
        self::assertFalse($plain->attributes->has(WorkerConnectionHygiene::FAILED_ATTRIBUTE));

        $hygiene->onTerminate(new TerminateEvent($kernel, $plain, new Response()));
        $hygiene->onTerminate(new TerminateEvent($kernel, $failed, new Response()));
    }

    public function testAFailedMessageIsRepairedOnlyAfterADatabaseFailure(): void
    {
        $hygiene  = new WorkerConnectionHygiene(new DoctrineStateRepairer(new RecordingRegistry(['default' => $this->pingedConnection(1)])));
        $envelope = new Envelope(new stdClass());

        $hygiene->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [new RuntimeException('a'), FakeDriverException::lockWaitTimeout()])));
        $hygiene->onMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [new RuntimeException('bug')])));
    }

    public function testDatabaseFailures(): void
    {
        self::assertTrue(WorkerConnectionHygiene::isDatabaseFailure(new ConnectionLost(FakeDriverException::deadlock(), null)));
        self::assertTrue(WorkerConnectionHygiene::isDatabaseFailure(EntityManagerClosed::create()));
        self::assertFalse(WorkerConnectionHygiene::isDatabaseFailure(new RuntimeException('bug')));
    }

    private function registry(bool $open): RecordingRegistry
    {
        return new RecordingRegistry([], ['default' => $this->manager($open)], ['default' => $this->manager(true)]);
    }

    private function manager(bool $open): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn($open);

        return $manager;
    }

    private function pingedConnection(int $times): Connection
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $platform->method('getDummySelectSQL')->willReturn('SELECT 1');
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects($this->exactly($times))->method('executeQuery');

        return $connection;
    }
}
