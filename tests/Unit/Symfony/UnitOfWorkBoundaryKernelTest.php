<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Closure;
use Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures\CallbackCommand;
use Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures\RecordingPrivateManager;
use Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

use function sys_get_temp_dir;

/**
 * 1.9.1 (ported tracker, audits NX CR1–CR3, OB-M1, OB-L1–L3): one kept-alive kernel in worker mode with the kernel
 * reset off (requests through the `http_kernel` service, never Kernel::handle(), so the services resetter never runs;
 * commands through an in-process console Application; Messenger workers on the kernel's dispatcher). The private
 * managers (and the host-flush probe) are reset only at the OUTERMOST unit-of-work boundaries, and a listener that
 * throws at a boundary never leaves the process "inside" a unit.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class UnitOfWorkBoundaryKernelTest extends TestCase
{
    private TestKernel $kernel;

    private RecordingPrivateManager $manager;

    private EventDispatcherInterface $dispatcher;

    private HttpKernel $http;

    private Application $console;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-transaction-doctrine-tests');
    }

    protected function setUp(): void
    {
        $this->kernel = new TestKernel();
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $manager   = $container->get(RecordingPrivateManager::class);
        self::assertInstanceOf(RecordingPrivateManager::class, $manager);
        $this->manager = $manager;
        $dispatcher    = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->dispatcher = $dispatcher;
        $http             = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $http);
        $this->http    = $http;
        $this->console = new Application($this->kernel);
        $this->console->setAutoExit(false);
        $this->console->setCatchExceptions(true);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    /** A command run inside a command keeps the outer command's private manager. */
    public function testNestedCommandsKeepTheOuterCommandsManager(): void
    {
        $this->addCommand('app:inner', static fn (): int => 0);
        $kept = [];
        $this->addCommand('app:outer', function () use (&$kept): int {
            $this->manager->live();
            $this->runCommand('app:inner');
            $kept[] = $this->manager->isInitialized();
            self::assertNotSame(0, $this->runCommand('zzz:no-such-command'));
            $kept[] = $this->manager->isInitialized();

            return 0;
        });

        self::assertSame(0, $this->runCommand('app:outer'));
        self::assertSame([true, true], $kept, 'nested command, unknown nested command');
        self::assertFalse($this->manager->isInitialized(), 'the outermost command reset it');
        $this->assertRequestResets('after nested commands');
    }

    /**
     * Audit XB-1: a server started from a console command (Swoole, ReactPHP, RoadRunner) serves each main request as a
     * unit of its own: every request resets at its start and at its terminate (1.9.0 did; the first 1.9.1 tracker held
     * them back as nested in the command), and the command still resets at its end.
     */
    public function testAServerCommandResetsAtEveryRequest(): void
    {
        $resets  = null;
        $atStart = [];
        $this->addCommand('app:serve', function () use (&$resets, &$atStart): int {
            $before = $this->manager->resets;
            for ($i = 0; $i < 3; ++$i) {
                $this->manager->live();
                $this->serve(function () use (&$atStart): void {
                    $atStart[] = $this->manager->isInitialized();
                    $this->manager->live();
                });
            }
            $resets = $this->manager->resets - $before;

            return 0;
        });

        self::assertSame(0, $this->runCommand('app:serve'));
        self::assertSame(6, $resets, 'start and terminate of each of the 3 requests');
        self::assertSame([false, false, false], $atStart);
        self::assertFalse($this->manager->isInitialized());
        $this->assertCommandResets('after a server command');
    }

    /** A main request handled in-process inside another one (and its terminate) keeps the outer request's manager. */
    public function testANestedMainRequestKeepsTheOuterRequestsManager(): void
    {
        $kept = null;
        $this->serve(function () use (&$kept): void {
            $this->manager->live();
            $this->serve(static function (): void {
            });
            $kept = $this->manager->isInitialized();
        });

        self::assertTrue($kept);
        self::assertFalse($this->manager->isInitialized(), 'the outer terminate reset it');
        $this->assertRequestResets('after a nested main request');
    }

    /**
     * A throwing `console.terminate` or `console.error` listener (Symfony then never dispatches the terminate) does
     * not leave the process inside a command: the next request and the next command reset again.
     */
    public function testThrowingConsoleListenersNeverStopTheNextReset(): void
    {
        $this->addCommand('app:ok', static fn (): int => 0);
        $this->addCommand('app:fail', static function (): never {
            throw new RuntimeException('command failed');
        });
        $throw = static function (): never {
            throw new RuntimeException('listener failed');
        };

        foreach ([[ConsoleEvents::TERMINATE, 'app:ok'], [ConsoleEvents::ERROR, 'app:fail']] as [$event, $command]) {
            $this->dispatcher->addListener($event, $throw);
            self::assertNotSame(0, $this->runCommand($command));
            $this->dispatcher->removeListener($event, $throw);

            $this->assertRequestResets($command . ' with a throwing ' . $event . ' listener');
            $this->assertCommandResets($command . ' with a throwing ' . $event . ' listener');
        }
    }

    /** A throwing `kernel.exception` listener (no finish_request, no terminate) never keeps the request in flight. */
    public function testAThrowingKernelExceptionListenerNeverKeepsTheRequestInFlight(): void
    {
        $throw = static function (): never {
            throw new RuntimeException('listener failed');
        };
        $this->dispatcher->addListener(KernelEvents::EXCEPTION, $throw, 4096);
        try {
            $this->serve(static function (): never {
                throw new RuntimeException('controller failed');
            });
            self::fail('the kernel.exception listener throws');
        } catch (RuntimeException) {
        } finally {
            $this->dispatcher->removeListener(KernelEvents::EXCEPTION, $throw);
        }

        $this->assertRequestResets('after a throwing kernel.exception listener');
        $this->assertCommandResets('after a throwing kernel.exception listener');
    }

    /** A worker run in-process inside a handler never ends the outer message; every message of a consumer resets. */
    public function testAWorkerInsideAHandlerNeverEndsTheOuterMessage(): void
    {
        $kept = null;
        $this->worker(1, function () use (&$kept): void {
            $this->manager->live();
            $this->worker(1, static function (): void {
            });
            $this->serve(static function (): void {
            });
            $kept = $this->manager->isInitialized();
        });

        self::assertTrue($kept, 'the inner worker and the in-process request kept the outer message\'s manager');
        self::assertFalse($this->manager->isInitialized(), 'the outer message reset it when handled');

        $atStart = [];
        $this->addCommand('app:consume', function () use (&$atStart): int {
            $this->worker(3, function () use (&$atStart): void {
                $atStart[] = $this->manager->isInitialized();
                $this->manager->live();
            });

            return 0;
        });
        self::assertSame(0, $this->runCommand('app:consume'));
        self::assertSame([false, false, false], $atStart, 'every message of messenger:consume starts reset');
        $this->assertRequestResets('after a consumer');
    }

    /**
     * A message skipped with `shouldHandle(false)` and a run aborted by a throwing received listener never leave a
     * message in flight: the next request and the next command reset again.
     */
    public function testSkippedAndAbortedMessagesNeverStayInFlight(): void
    {
        $handled = 0;
        $skipped = 0;
        $this->worker(2, static function () use (&$handled): void {
            ++$handled;
        }, static function (WorkerMessageReceivedEvent $event) use (&$skipped): void {
            if (++$skipped === 1) {
                $event->shouldHandle(false);
            }
        });
        self::assertSame(2, $handled, 'the skipped message is delivered again by the in-memory transport');
        $this->assertRequestResets('after a skipped message');
        $this->assertCommandResets('after a skipped message');

        try {
            $this->worker(1, static function (): void {
            }, static function (): never {
                throw new RuntimeException('received listener failed');
            });
            self::fail('the received listener throws');
        } catch (RuntimeException) {
        }

        $this->assertRequestResets('after an aborted run');
        $this->assertCommandResets('after an aborted run');
    }

    /** The next top-level request resets the manager at its start and at its terminate. */
    private function assertRequestResets(string $label): void
    {
        $this->manager->live();
        $atStart = null;
        $this->serve(function () use (&$atStart): void {
            $atStart = $this->manager->isInitialized();
            $this->manager->live();
        });
        self::assertFalse($atStart, $label . ': a top-level request resets at its start');
        self::assertFalse($this->manager->isInitialized(), $label . ': and at its terminate');
    }

    /** The next top-level command resets the manager at its start and at its end. */
    private function assertCommandResets(string $label): void
    {
        $atStart = null;
        $this->addCommand('app:next', function () use (&$atStart): int {
            $atStart = $this->manager->isInitialized();
            $this->manager->live();

            return 0;
        });
        $this->manager->live();
        self::assertSame(0, $this->runCommand('app:next'));
        self::assertFalse($atStart, $label . ': the next command resets at its start');
        self::assertFalse($this->manager->isInitialized(), $label . ': and at its end');
    }

    /**
     * One main request through the `http_kernel` service (the services resetter never runs); the closure is the
     * controller.
     */
    private function serve(Closure $body): void
    {
        $request = Request::create('/');
        $request->attributes->set('_controller', static function () use ($body): Response {
            $body();

            return new Response('ok');
        });
        $response = $this->http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        $this->http->terminate($request, $response);
    }

    /**
     * A real Messenger worker on the kernel's dispatcher: $count messages, then it stops when idle.
     */
    private function worker(int $count, Closure $handler, ?Closure $onReceived = null): void
    {
        $transport = new InMemoryTransport();
        for ($i = 0; $i < $count; ++$i) {
            $transport->send(new Envelope(new stdClass()));
        }

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use ($handler): Envelope {
            $handler();

            return $message instanceof Envelope ? $message : new Envelope($message);
        });
        $worker = new Worker(['memory' => $transport], $bus, $this->dispatcher);
        $stop   = static function (WorkerRunningEvent $event) use ($worker): void {
            if ($event->getWorker() === $worker && $event->isWorkerIdle()) {
                $worker->stop();
            }
        };
        $this->dispatcher->addListener(WorkerRunningEvent::class, $stop, -1);
        if ($onReceived instanceof Closure) {
            $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, $onReceived);
        }

        try {
            $worker->run(['sleep' => 0]);
        } finally {
            $this->dispatcher->removeListener(WorkerRunningEvent::class, $stop);
            if ($onReceived instanceof Closure) {
                $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, $onReceived);
            }
        }
    }

    /**
     * @param Closure(): int $body
     */
    private function addCommand(string $name, Closure $body): void
    {
        $this->console->addCommand(new CallbackCommand($name, $body));
    }

    private function runCommand(string $command): int
    {
        $input = new ArrayInput(['command' => $command]);
        $input->setInteractive(false);

        return $this->console->run($input, new NullOutput());
    }
}
