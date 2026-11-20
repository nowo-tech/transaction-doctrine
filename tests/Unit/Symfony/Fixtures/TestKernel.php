<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures;

use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\Symfony\NowoTransactionDoctrineBundle;
use Nowo\TransactionDoctrine\Tests\Unit\Support\RecordingRegistry;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitRetryEvent;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function md5;
use function serialize;
use function sys_get_temp_dir;

/**
 * Minimal application: FrameworkBundle, TwigBundle and NowoTransactionDoctrineBundle with the given config, an empty
 * ManagerRegistry as `doctrine`, and the fixture controllers.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $config nowo_transaction_doctrine config
     */
    public function __construct(private readonly array $config = [])
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new NowoTransactionDoctrineBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 4);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-transaction-doctrine-tests/' . md5(serialize($this->config)) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-transaction-doctrine-tests/logs';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'test-only',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'translator'            => ['default_path' => '%kernel.project_dir%/translations', 'fallbacks' => ['en']],
            'default_locale'        => 'en',
        ]);
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates', 'strict_variables' => true]);
        $container->extension('nowo_transaction_doctrine', $this->config);

        $services = $container->services();
        $services->set('doctrine', RecordingRegistry::class)->public();
        $services->set('logger', NullLogger::class);
        $services->set(Attempts::class)->public();
        $services->set(ReplayLog::class)->public()
            ->tag('kernel.event_listener', ['event' => WriteUnitRetryEvent::class, 'method' => 'onRetry']);
        $services->set(LowResetter::class)->autowire()->autoconfigure();
        $services->set(HighResetter::class)->autowire()->autoconfigure();
        $services->set(RecordingPrivateManager::class)->public()->tag(PrivateEntityManager::TAG);
        $services->set(ConflictController::class)->autowire()->autoconfigure()->tag('controller.service_arguments')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('conflict_page', '/conflict')->controller([ConflictController::class, 'conflict']);
        $routes->add('conflict_api', '/api/conflict')->controller([ConflictController::class, 'conflict']);
        $routes->add('committed_api', '/api/committed')->controller([ConflictController::class, 'committed']);
        $routes->add('unit', '/unit')->controller([ConflictController::class, 'unit'])->methods(['POST']);
        $routes->add('org_listed_route', '/listed')->controller([ConflictController::class, 'listed'])->methods(['POST']);
        $routes->add('always', '/always')->controller([ConflictController::class, 'always'])->methods(['POST']);
    }
}
