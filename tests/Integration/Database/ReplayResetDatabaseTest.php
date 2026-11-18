<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Closure;
use Doctrine\ORM\ORMInvalidArgumentException;
use LogicException;
use Nowo\TransactionDoctrine\Symfony\EventListener\WriteUnitControllerListener;
use Nowo\TransactionDoctrine\Symfony\Messenger\WriteUnitMiddleware;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\AddNote;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Counter;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\CounterMemo;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Note;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\NoteController;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitRetryEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Spec 241 T020 (SYMFONY-BEACON): a request memo holding an entity is emptied between write-unit attempts, for each
 * entry point (#[AsWriteUnit], `controller_routes`, the Messenger middleware), on MySQL 8.4, MariaDB 11.4 and
 * PostgreSQL 16. Attempt 1 hits a real lock wait timeout in its flush (the ORM closes the manager, the unit resets it);
 * the memo is reset, so attempt 2 works on managed entities of the new manager and commits.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ReplayResetDatabaseTest extends DatabaseTestCase
{
    /** @var list<WriteUnitRetryEvent> */
    private array $events = [];

    #[DataProvider('allServers')]
    public function testAnAsWriteUnitControllerReplaysWithAResetMemo(string $server): void
    {
        $memo       = $this->prepare($server);
        $controller = new NoteController(fn (int $attempt) => $this->addNote($memo, $attempt, 2));
        $request    = Request::create('/notes', 'POST');
        $request->attributes->set('_route', 'note_add');

        $response = $this->handle(new WriteUnitControllerListener($this->unit($memo), $this->registry), $controller->marked(...), $request);

        self::assertSame('added after 2', $response->getContent());
        $this->assertReplayedOnce($memo, 'note_add');
    }

    #[DataProvider('allServers')]
    public function testAListedRouteReplaysWithAResetMemo(string $server): void
    {
        $memo       = $this->prepare($server);
        $controller = new NoteController(fn (int $attempt) => $this->addNote($memo, $attempt, 2));
        $request    = Request::create('/notes', 'POST');
        $request->attributes->set('_route', 'tenant_note_add');

        $listener = new WriteUnitControllerListener($this->unit($memo), $this->registry, null, ['*_note_add'], false);
        $response = $this->handle($listener, $controller->listed(...), $request);

        self::assertSame('added after 2', $response->getContent());
        $this->assertReplayedOnce($memo, 'tenant_note_add');
    }

    #[DataProvider('allServers')]
    public function testAConsumedMessageReplaysWithAResetMemo(string $server): void
    {
        $memo     = $this->prepare($server);
        $attempts = 0;
        $handler  = function (AddNote $message) use ($memo, &$attempts): string {
            $this->addNote($memo, ++$attempts, $message->noteId);

            return 'added';
        };
        $middleware = new WriteUnitMiddleware($this->unit($memo));
        $handle     = new HandleMessageMiddleware(new HandlersLocator([AddNote::class => [$handler]]));

        $result = $middleware->handle(new Envelope(new AddNote(2), [new ConsumedByWorkerStamp()]), new StackMiddleware([$middleware, $handle]));

        self::assertSame(2, $attempts);
        self::assertSame('added', $result->last(HandledStamp::class)?->getResult());
        $this->assertReplayedOnce($memo, AddNote::class);
    }

    /**
     * The hazard the hook removes: without the reset, the memo hands the replay the failed attempt's detached entity
     * and its flush fails with ORMInvalidArgumentException (a 500 instead of the conflict answer).
     */
    #[DataProvider('allServers')]
    public function testWithoutTheResetTheReplayFailsOnTheDetachedEntity(string $server): void
    {
        $memo = $this->prepare($server);
        $unit = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0));

        try {
            $unit->run(fn (int $attempt) => $this->addNote($memo, $attempt, 2, false));
            self::fail('The replay succeeded with a detached entity.');
        } catch (ORMInvalidArgumentException) {
            self::assertSame(0, $memo->resets);
        } finally {
            $this->releasePeer();
        }

        self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_note WHERE id = 2'));
    }

    /**
     * Audit TA-2: a resetter that opens a transaction (or leaves a persist pending) would make the replay's flush run
     * nested: run() would return with nothing committed. The unit discards the leftovers and gives up instead.
     */
    #[DataProvider('allServers')]
    public function testAResetterThatLeavesATransactionOrPendingChangesAbandonsTheReplay(string $server): void
    {
        foreach (['transaction', 'persist'] as $leftover) {
            $memo  = $this->prepare($server);
            $leaky = new class($this, $leftover) implements ResetBetweenAttemptsInterface {
                public function __construct(private readonly ReplayResetDatabaseTest $test, private readonly string $leftover)
                {
                }

                public function resetBetweenAttempts(): void
                {
                    $this->test->leaveBehind($this->leftover);
                }
            };
            $unit   = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0), null, null, null, null, null, [$memo, $leaky]);
            $calls  = 0;
            $caught = null;

            try {
                $unit->run(function (int $attempt) use ($memo, &$calls): void {
                    ++$calls;
                    $this->addNote($memo, $attempt, 2);
                });
            } catch (ConcurrencyConflict $conflict) {
                $caught = $conflict;
            } finally {
                $this->releasePeer();
            }

            self::assertNotNull($caught, $leftover);
            self::assertInstanceOf(LogicException::class, $caught->resetFailure, $leftover);
            self::assertFalse($caught->committed);
            self::assertSame(1, $calls, $leftover);
            self::assertSame(0, $this->connection->getTransactionNestingLevel(), $leftover);
            self::assertSame([], $this->manager()->getUnitOfWork()->getScheduledEntityInsertions(), $leftover);
            self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 3'), $leftover);
            self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_note WHERE id = 2'), $leftover);
            $this->tearDown();
        }
    }

    /**
     * Audit TA-6: a resetter (or a retry listener) that opens a transaction and then throws. The unit discards what it
     * left before giving up, so the connection is back at nesting level 0 and nothing it wrote is committed later.
     */
    #[DataProvider('allServers')]
    public function testAResetterThatOpensATransactionAndThrowsLeavesLevelZero(string $server): void
    {
        foreach (['resetter', 'listener'] as $where) {
            $memo   = $this->prepare($server);
            $broken = function (): never {
                $this->leaveBehind('transaction');

                throw new RuntimeException('reset failed after BEGIN');
            };
            $dispatcher = new EventDispatcher();
            $resetters  = [$memo];
            if ($where === 'listener') {
                $dispatcher->addListener(WriteUnitRetryEvent::class, $broken);
            } else {
                $resetters[] = new class($broken) implements ResetBetweenAttemptsInterface {
                    /** @param Closure(): never $broken */
                    public function __construct(private readonly Closure $broken)
                    {
                    }

                    public function resetBetweenAttempts(): void
                    {
                        ($this->broken)();
                    }
                };
            }

            $unit   = new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0), null, null, null, null, null, $resetters, $dispatcher);
            $caught = null;

            try {
                $unit->run(fn (int $attempt) => $this->addNote($memo, $attempt, 2));
            } catch (ConcurrencyConflict $conflict) {
                $caught = $conflict;
            } finally {
                $this->releasePeer();
            }

            self::assertNotNull($caught, $where);
            self::assertInstanceOf(RuntimeException::class, $caught->resetFailure, $where);
            self::assertSame(0, $this->connection->getTransactionNestingLevel(), $where);
            self::assertFalse($this->connection->isTransactionActive(), $where);
            self::assertSame('1', (string) $this->connection->fetchOne('SELECT 1'), $where);
            self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 3'), $where);
            self::assertSame(0, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_note WHERE id = 2'), $where);
            $this->tearDown();
        }
    }

    /** What a misbehaving resetter leaves: an open transaction, or a persisted entity never flushed. */
    public function leaveBehind(string $leftover): void
    {
        if ($leftover === 'transaction') {
            $this->connection->beginTransaction();
            $this->connection->executeStatement('INSERT INTO ddrb_counter (id, counter_value) VALUES (3, 3)');

            return;
        }

        $this->manager()->persist(new Counter(3, 3));
    }

    /** A peer session holds counter 1 (set to 7) until attempt 2 starts; lock waits are 1 s. */
    private function prepare(string $server): CounterMemo
    {
        $this->boot($server);
        $this->connection->executeStatement($this->isPostgreSql() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 7 WHERE id = 1'));

        return new CounterMemo($this->registry);
    }

    private function releasePeer(): void
    {
        $this->otherSession()->execute('COMMIT');
    }

    /**
     * Attempt 1 loads counter 1 into the memo and blocks on its update in the flush; attempt 2 (peer committed first)
     * must get a counter the current manager manages.
     */
    private function addNote(CounterMemo $memo, int $attempt, int $noteId, bool $checkManaged = true): void
    {
        if ($attempt === 2) {
            self::assertSame('OK', $this->otherSession()->execute('COMMIT'));
        }

        $manager = $this->manager();
        $counter = $memo->counter();
        if ($checkManaged) {
            self::assertTrue($manager->contains($counter), 'The memo handed attempt ' . $attempt . ' a detached entity.');
        }

        ++$counter->value;
        $manager->persist(new Note($noteId, $counter));
        $manager->flush();
    }

    private function unit(CounterMemo $memo): WriteUnit
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WriteUnitRetryEvent::class, function (WriteUnitRetryEvent $event): void {
            $this->events[] = $event;
        });

        return new WriteUnit($this->registry, new WriteUnitPolicy(3, 0, 0, 0), null, null, null, null, null, [$memo], $dispatcher);
    }

    private function handle(WriteUnitControllerListener $listener, callable $controller, Request $request): Response
    {
        $event = new ControllerArgumentsEvent($this->createMock(HttpKernelInterface::class), $controller, [], $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($event);
        $response = ($event->getController())();
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    private function assertReplayedOnce(CounterMemo $memo, string $unit): void
    {
        self::assertSame(1, $memo->resets);
        self::assertSame(1, $this->registry->resets, 'The failed flush closed the manager; the unit reset it.');
        self::assertCount(1, $this->events);
        self::assertSame(2, $this->events[0]->attempt);
        self::assertSame($unit, $this->events[0]->unit);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(8, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
        self::assertSame(1, (int) $this->freshValue('SELECT counter_id FROM ddrb_note WHERE id = 2'));
    }
}
