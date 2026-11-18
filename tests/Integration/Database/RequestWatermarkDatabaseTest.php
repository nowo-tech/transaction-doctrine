<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database;

use Closure;
use Doctrine\DBAL\Connection;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\Symfony\EventListener\RequestCommitWatermark;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\DatabaseTestCase;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use function json_decode;

/**
 * Audit TH X-1 / X-2 on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16) through a real HttpKernel: the request
 * commit watermark taken at kernel.request (RequestCommitWatermark) and the responder. A controller commits a note,
 * then a write unit (one attempt) meets a real lock wait timeout on counter 1, held by a peer session: the unit itself
 * committed nothing, but the request did, so the answer is CONFLICT_002 without Retry-After. Commits before the mark
 * (an earlier request of the same worker, a listener ahead of the mark) do not count.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RequestWatermarkDatabaseTest extends DatabaseTestCase
{
    private ?HttpKernel $kernel = null;

    #[DataProvider('allServers')]
    public function testACommitThenAConflictAnswersConflict002(string $server): void
    {
        $this->prepare($server);

        $response = $this->kernel(['/api/notes' => function (): Response {
            $this->commitNote(2);

            return $this->conflictingUnit();
        }])->handle(Request::create('/api/notes', 'POST'));

        $this->assertAnswer($response, 'CONFLICT_002', null);
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_note WHERE id = 2'), 'The earlier commit is durable: a blind retry would repeat it.');
    }

    #[DataProvider('allServers')]
    public function testAConflictWithNoCommitAnswersConflict001(string $server): void
    {
        $this->prepare($server);

        $response = $this->kernel(['/api/notes' => $this->conflictingUnit(...)])->handle(Request::create('/api/notes', 'POST'));

        $this->assertAnswer($response, 'CONFLICT_001', '1');
    }

    /**
     * Commits before the mark: the previous request of the same (worker) kernel, and a host listener that runs ahead of
     * the mark in this request. The ledger is monotonic and only moves after the mark count.
     */
    #[DataProvider('allServers')]
    public function testACommitBeforeTheMarkAnswersConflict001(string $server): void
    {
        $this->prepare($server);
        $kernel = $this->kernel([
            '/notes' => function (): Response {
                $this->commitNote(2);

                return new Response('saved');
            },
            '/api/notes' => $this->conflictingUnit(...),
        ], function (RequestEvent $event): void {
            if ($event->isMainRequest() && $event->getRequest()->getPathInfo() === '/api/notes') {
                $this->commitNote(3);
            }
        });

        self::assertSame('saved', $kernel->handle(Request::create('/notes', 'POST'))->getContent());
        $response = $kernel->handle(Request::create('/api/notes', 'POST'));

        $this->assertAnswer($response, 'CONFLICT_001', '1');
        self::assertSame(2, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_note WHERE id IN (2, 3)'));
    }

    /** A sub-request (a fragment) reads the main request's mark: a commit in the page before it counts. */
    #[DataProvider('allServers')]
    public function testASubRequestReadsTheMainRequestsMark(string $server): void
    {
        $this->prepare($server);
        $kernel = $this->kernel([
            '/page' => function (): Response {
                $this->commitNote(2);

                return $this->fragment();
            },
            '/quiet-page'    => $this->fragment(...),
            '/api/_fragment' => $this->conflictingUnit(...),
        ]);

        $this->assertAnswer($kernel->handle(Request::create('/page', 'POST')), 'CONFLICT_002', null);
        $this->assertAnswer($kernel->handle(Request::create('/quiet-page', 'POST')), 'CONFLICT_001', '1');
    }

    protected function tearDown(): void
    {
        $this->kernel = null;
        parent::tearDown();
    }

    /** Commit detection on the test's connection; the peer holds counter 1; lock waits are 1 s. */
    private function prepare(string $server): void
    {
        $this->ledger = new CommitLedger();
        $this->boot($server);
        $this->connection->executeStatement($this->isPostgreSql() ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 7 WHERE id = 1'));
    }

    private function commitNote(int $id): void
    {
        $this->connection->transactional(static function (Connection $connection) use ($id): void {
            $connection->executeStatement('INSERT INTO ddrb_note (id, counter_id) VALUES (' . $id . ', 2)');
        });
    }

    /** One attempt that waits on counter 1 until the lock wait timeout: ConcurrencyConflict, committed false. */
    private function conflictingUnit(): Response
    {
        $ledger = $this->ledger;
        self::assertInstanceOf(CommitLedger::class, $ledger);
        $unit = new WriteUnit($this->registry, new WriteUnitPolicy(1, 0, 0, 0), null, null, null, null, $ledger);

        return $unit->run(function (): Response {
            $this->connection->transactional(static function (Connection $connection): void {
                $connection->executeStatement('UPDATE ddrb_counter SET counter_value = 5 WHERE id = 1');
            });

            return new Response('not reached');
        });
    }

    private function fragment(): Response
    {
        $kernel = $this->kernel;
        self::assertInstanceOf(HttpKernel::class, $kernel);

        return $kernel->handle(Request::create('/api/_fragment'), HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * @param array<string, Closure(): Response> $routes
     * @param (Closure(RequestEvent): void)|null $beforeTheMark A host listener ahead of the mark
     */
    private function kernel(array $routes, ?Closure $beforeTheMark = null): HttpKernel
    {
        $ledger = $this->ledger;
        self::assertInstanceOf(CommitLedger::class, $ledger);
        $stack      = new RequestStack();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(KernelEvents::REQUEST, (new RequestCommitWatermark($ledger, $stack))->onRequest(...), RequestCommitWatermark::PRIORITY);
        if ($beforeTheMark instanceof Closure) {
            $dispatcher->addListener(KernelEvents::REQUEST, $beforeTheMark, RequestCommitWatermark::PRIORITY + 1);
        }

        $dispatcher->addListener(KernelEvents::REQUEST, static function (RequestEvent $event) use ($routes): void {
            $event->getRequest()->attributes->set('_controller', $routes[$event->getRequest()->getPathInfo()]);
        }, 32);
        $dispatcher->addListener(KernelEvents::EXCEPTION, new ConcurrencyConflictResponder(new ConflictResponsePolicy()), 64);

        return $this->kernel = new HttpKernel($dispatcher, new ControllerResolver(), $stack, new ArgumentResolver());
    }

    private function assertAnswer(Response $response, string $code, ?string $retryAfter): void
    {
        self::assertSame(409, $response->getStatusCode());
        self::assertSame($code, $response->headers->get('X-Conflict-Code'));
        self::assertSame($retryAfter, $response->headers->get('Retry-After'));
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame($code, $body['code']);
    }
}
