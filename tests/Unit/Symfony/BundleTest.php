<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle;
use Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures\Attempts;
use Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures\ReplayLog;
use Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function dirname;
use function json_decode;
use function sys_get_temp_dir;

/**
 * The bundle in a minimal application: default wiring, a 503 host, everything switched off, invalid config.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class BundleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-transaction-doctrine-tests');
    }

    public function testTheBundleLivesAtThePackageRoot(): void
    {
        self::assertSame(dirname(__DIR__, 3), (new NowoTransactionDoctrineBundle())->getPath());
    }

    public function testAConflictAnswers409WithTheBrandedPageAndJson(): void
    {
        $kernel = $this->boot();

        $page = $kernel->handle(Request::create('/conflict'));
        self::assertSame(409, $page->getStatusCode());
        self::assertSame('1', $page->headers->get('Retry-After'));
        self::assertStringContainsString('Someone else was saving at the same moment', (string) $page->getContent());
        self::assertStringContainsString('data-testid="concurrency-conflict-retry"', (string) $page->getContent());

        $json = $kernel->handle(Request::create('/api/conflict'));
        self::assertSame(409, $json->getStatusCode());
        self::assertSame('concurrency_conflict', json_decode((string) $json->getContent(), true)['error']);
        $kernel->shutdown();
    }

    public function testAWriteUnitControllerIsReplayed(): void
    {
        $kernel   = $this->boot(['backoff' => ['floor_ms' => 0, 'base_ms' => 0, 'cap_ms' => 0]]);
        $response = $kernel->handle(Request::create('/unit', 'POST'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('saved after 2', $response->getContent());
        self::assertSame(['high', 'low', 'event 2 unit deadlock'], $this->replayLog($kernel)->entries);
        $kernel->shutdown();
    }

    public function testAWriteUnitThatAlwaysConflictsAnswersTheConflict(): void
    {
        $kernel   = $this->boot(['max_attempts' => 2, 'backoff' => ['floor_ms' => 0, 'base_ms' => 0, 'cap_ms' => 0], 'conflict_response' => ['retry_after' => 3]]);
        $response = $kernel->handle(Request::create('/always', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']));
        $attempts = $kernel->getContainer()->get(Attempts::class);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('3', $response->headers->get('Retry-After'));
        self::assertInstanceOf(Attempts::class, $attempts);
        self::assertSame(2, $attempts->count);
        self::assertSame(['high', 'low', 'event 2 always lock_wait_timeout'], $this->replayLog($kernel)->entries);
        $kernel->shutdown();
    }

    public function testAListedRouteIsReplayed(): void
    {
        $kernel   = $this->boot(['controller_attribute' => false, 'controller_routes' => ['*_listed_route'], 'backoff' => ['floor_ms' => 0, 'base_ms' => 0, 'cap_ms' => 0]]);
        $response = $kernel->handle(Request::create('/listed', 'POST'));

        self::assertSame('saved after 2', $response->getContent());
        self::assertSame(['high', 'low', 'event 2 org_listed_route deadlock'], $this->replayLog($kernel)->entries);
        self::assertSame(500, $kernel->handle(Request::create('/unit', 'POST'))->getStatusCode());
        $kernel->shutdown();
    }

    public function testAHostThatKeeps503(): void
    {
        $kernel   = $this->boot(['conflict_response' => ['status' => 503, 'retry_after' => 5]]);
        $response = $kernel->handle(Request::create('/conflict'));
        $policy   = $kernel->getContainer()->get('nowo_transaction_doctrine.conflict_response_policy');

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertInstanceOf(ConflictResponsePolicy::class, $policy);
        self::assertSame(503, $policy->status);
        self::assertSame(503, $kernel->getContainer()->getParameter('nowo_transaction_doctrine.conflict_response.status'));
        self::assertTrue($kernel->getContainer()->has('nowo_transaction_doctrine.write_unit_middleware'));
        self::assertTrue($kernel->getContainer()->has('nowo_transaction_doctrine.write_unit'));
        self::assertTrue($this->testContainer($kernel)->has('nowo_transaction_doctrine.commit_ledger'));
        $kernel->shutdown();
    }

    public function testEverythingCanBeSwitchedOff(): void
    {
        $kernel = $this->boot([
            'controller_attribute' => false,
            'messenger_middleware' => false,
            'worker_hygiene'       => false,
            'commit_detection'     => false,
            'conflict_response'    => ['enabled' => false],
        ]);
        $container = $this->testContainer($kernel);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $kernel->handle(Request::create('/conflict'))->getStatusCode());
        self::assertFalse($container->has('nowo_transaction_doctrine.write_unit_middleware'));
        self::assertFalse($container->has('nowo_transaction_doctrine.worker_connection_hygiene'));
        self::assertFalse($container->has('nowo_transaction_doctrine.write_unit_controller_listener'));
        self::assertTrue($container->has('nowo_transaction_doctrine.conflict_response_policy'));
        self::assertFalse($container->has('nowo_transaction_doctrine.commit_ledger'));
        $kernel->shutdown();
    }

    /** Audit TH X-1 / X-2 through the container: the watermark is wired at kernel.request, the header on every answer. */
    public function testACommitEarlierInTheRequestAnswersConflict002(): void
    {
        $kernel = $this->boot();

        $committed = $kernel->handle(Request::create('/api/committed', 'POST'));
        self::assertSame(409, $committed->getStatusCode());
        self::assertSame('CONFLICT_002', $committed->headers->get('X-Conflict-Code'));
        self::assertFalse($committed->headers->has('Retry-After'));

        $plain = $kernel->handle(Request::create('/api/conflict', 'POST'));
        self::assertSame('CONFLICT_001', $plain->headers->get('X-Conflict-Code'));
        self::assertSame('1', $plain->headers->get('Retry-After'));
        self::assertTrue($this->testContainer($kernel)->has('nowo_transaction_doctrine.request_commit_watermark'));
        $kernel->shutdown();

        $off = $this->boot(['commit_detection' => false]);
        self::assertSame('CONFLICT_001', $off->handle(Request::create('/api/committed', 'POST'))->headers->get('X-Conflict-Code'), 'Without commit detection the failure alone decides.');
        self::assertFalse($this->testContainer($off)->has('nowo_transaction_doctrine.request_commit_watermark'));
        $off->shutdown();
    }

    public function testTransientFailuresCanBeMapped(): void
    {
        $kernel    = $this->boot(['conflict_response' => ['map_transient_failures' => true]]);
        $container = $this->testContainer($kernel);

        self::assertTrue($container->has('nowo_transaction_doctrine.transient_failure_conflict_mapper'));
        $kernel->shutdown();
    }

    public function testAnInvalidStatusIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(['conflict_response' => ['status' => 500]]);
    }

    public function testAnUnknownReplayKindIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->boot(['replay_on' => ['nope']]);
    }

    private function replayLog(TestKernel $kernel): ReplayLog
    {
        $log = $kernel->getContainer()->get(ReplayLog::class);
        self::assertInstanceOf(ReplayLog::class, $log);

        return $log;
    }

    private function testContainer(TestKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = []): TestKernel
    {
        $kernel = new TestKernel($config);
        $kernel->boot();

        return $kernel;
    }
}
