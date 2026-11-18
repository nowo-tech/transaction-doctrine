<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictExceptionMapperInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\Symfony\Conflict\TransientFailureConflictMapper;
use Nowo\TransactionDoctrine\Symfony\EventListener\ConcurrencyConflictResponder;
use Nowo\TransactionDoctrine\Symfony\EventListener\RequestCommitWatermark;
use Nowo\TransactionDoctrine\Symfony\EventListener\WorkerConnectionHygiene;
use Nowo\TransactionDoctrine\Symfony\EventListener\WriteUnitControllerListener;
use Nowo\TransactionDoctrine\Symfony\Messenger\WriteUnitMiddleware;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Nowo\TransactionDoctrine\WriteUnit\DoctrineStateRepairer;
use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;

use function array_map;
use function dirname;
use function implode;
use function interface_exists;
use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Optional Symfony integration of nowo-tech/transaction-doctrine (register it in config/bundles.php). Config root
 * `nowo_transaction_doctrine`:
 *
 * - write units (`max_attempts`, `backoff`, `replay_on`, `commit_detection`) for #[AsWriteUnit] controllers,
 *   `controller_routes`, the Messenger middleware and the programmatic WriteUnitInterface service;
 * - `conflict_response`: the 409 (or 503) + `Retry-After` + `X-Conflict-Code` answer of a lock conflict, its template
 *   and JSON code, and the ConflictResponsePolicy service every bundle reads the status from;
 * - with `commit_detection`, the request commit watermark (RequestCommitWatermark at kernel.request): a conflict
 *   after a commit earlier in the same request answers CONFLICT_002 (ConflictCode::forRequest());
 * - request memos: services implementing ResetBetweenAttemptsInterface are autoconfigured (tag
 *   `nowo_transaction_doctrine.reset_between_attempts`, ordered by priority) and emptied before every replay; a
 *   WriteUnitRetryEvent is dispatched on `event_dispatcher` then;
 * - `worker_hygiene`: closed entity managers reset and failed connections pinged between requests and messages.
 *
 * Every service is stateless (FrankenPHP worker mode with kernel reset off). Templates and translations live at the
 * package root (`templates/`, `translations/`), so the Twig namespace is `@NowoTransactionDoctrine` and a host
 * overrides a template in `templates/bundles/NowoTransactionDoctrineBundle/`.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoTransactionDoctrineBundle extends AbstractBundle
{
    public const ALIAS = 'nowo_transaction_doctrine';

    protected string $extensionAlias = self::ALIAS;

    public function getPath(): string
    {
        return dirname(__DIR__, 2);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $kinds = array_map(static fn (TransientFailureKind $kind): string => $kind->value, TransientFailureKind::cases());

        $definition->rootNode()
            ->children()
                ->integerNode('max_attempts')->defaultValue(WriteUnitPolicy::DEFAULT_MAX_ATTEMPTS)->min(1)->max(10)
                    ->info('Attempts of a write unit, the first one included (1 = no replay).')->end()
                ->arrayNode('backoff')
                    ->info('Full-jitter pause between two attempts: uniform between floor_ms and min(cap_ms, base_ms × 2^(attempt − 1)).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('floor_ms')->defaultValue(WriteUnitPolicy::DEFAULT_FLOOR_MS)->min(0)->end()
                        ->integerNode('base_ms')->defaultValue(WriteUnitPolicy::DEFAULT_BASE_MS)->min(0)->end()
                        ->integerNode('cap_ms')->defaultValue(WriteUnitPolicy::DEFAULT_CAP_MS)->min(0)->end()
                    ->end()
                ->end()
                ->arrayNode('replay_on')
                    ->info('Transient kinds a write unit replays: ' . implode(', ', $kinds) . '.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifNotInArray($kinds)
                            ->thenInvalid('Unknown transient kind %s.')
                        ->end()
                    ->end()
                    ->defaultValue(array_map(static fn (TransientFailureKind $kind): string => $kind->value, WriteUnitPolicy::DEFAULT_REPLAY_KINDS))
                ->end()
                ->booleanNode('controller_attribute')->defaultTrue()
                    ->info('Run controllers marked #[AsWriteUnit] as write units.')->end()
                ->arrayNode('controller_routes')
                    ->info('Route names (exact; "*" is an explicit wildcard) that run as write units, for controllers the host cannot annotate. A bundle route also needs that bundle\'s ConflictResponseFormatterInterface.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('messenger_middleware')->defaultTrue()
                    ->info('Register nowo_transaction_doctrine.write_unit_middleware (add it to your buses before doctrine_transaction).')->end()
                ->booleanNode('commit_detection')->defaultTrue()
                    ->info('Count commits through a DBAL middleware (doctrine.middleware tag): a failed attempt that already committed is never replayed, and a conflict after a commit earlier in the same request answers CONFLICT_002 (request watermark taken at kernel.request).')->end()
                ->booleanNode('worker_hygiene')->defaultTrue()
                    ->info('Reset closed entity managers per request and repair connections after a DBAL / ORM failure.')->end()
                ->arrayNode('conflict_response')
                    ->info('The answer to a lock conflict the client may retry (409 + Retry-After by default; 503 keeps the 1.2 behaviour).')
                    ->canBeDisabled()
                    ->children()
                        ->integerNode('status')->defaultValue(ConflictResponsePolicy::STATUS_CONFLICT)
                            ->validate()
                                ->ifNotInArray(ConflictResponsePolicy::STATUSES)
                                ->thenInvalid('conflict_response.status must be 409 or 503, got %s.')
                            ->end()
                        ->end()
                        ->integerNode('retry_after')->defaultValue(ConflictResponsePolicy::DEFAULT_RETRY_AFTER)->min(0)
                            ->info('Retry-After seconds.')->end()
                        ->scalarNode('template')->defaultValue(ConcurrencyConflictResponder::DEFAULT_TEMPLATE)->cannotBeEmpty()
                            ->info('Twig template of the HTML answer (variables: status_code, retry_url, retry_after, ui_kit, partial, conflict_code).')->end()
                        ->scalarNode('json_error')->defaultValue(ConcurrencyConflictResponder::DEFAULT_ERROR_CODE)->cannotBeEmpty()
                            ->info('The "error" code of the JSON answer.')->end()
                        ->arrayNode('json_path_prefixes')
                            ->info('Path prefixes always answered in JSON (besides XHR and JSON requests).')
                            ->scalarPrototype()->end()
                            ->defaultValue(['/api/'])
                        ->end()
                        ->booleanNode('map_transient_failures')->defaultFalse()
                            ->info('Also answer any transient storage failure that reaches the kernel (not only write-unit conflicts).')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{max_attempts: int, backoff: array{floor_ms: int, base_ms: int, cap_ms: int}, replay_on: list<string>, controller_attribute: bool, controller_routes: list<string>, commit_detection: bool, messenger_middleware: bool, worker_hygiene: bool, conflict_response: array{enabled: bool, status: int, retry_after: int, template: string, json_error: string, json_path_prefixes: list<string>, map_transient_failures: bool}} $config */
        $services = $container->services();
        $prefix   = self::ALIAS . '.';
        $conflict = $config['conflict_response'];

        $services->set($prefix . 'conflict_response_policy', ConflictResponsePolicy::class)
            ->args([$conflict['status'], $conflict['retry_after']])
            ->public();
        $services->alias(ConflictResponsePolicy::class, $prefix . 'conflict_response_policy');

        $services->set($prefix . 'state_repairer', DoctrineStateRepairer::class)
            ->args([service('doctrine'), service('logger')->nullOnInvalid()])
            ->tag('monolog.logger', ['channel' => 'transaction_doctrine']);
        $services->alias(DoctrineStateRepairer::class, $prefix . 'state_repairer');

        if ($config['commit_detection']) {
            $services->set($prefix . 'commit_ledger', CommitLedger::class);
            $services->alias(CommitLedger::class, $prefix . 'commit_ledger');
            $services->set($prefix . 'commit_tracking_middleware', CommitTrackingMiddleware::class)
                ->args([service($prefix . 'commit_ledger')])
                ->tag('doctrine.middleware');
            $services->set($prefix . 'request_commit_watermark', RequestCommitWatermark::class)
                ->args([service($prefix . 'commit_ledger'), service('request_stack')->nullOnInvalid()])
                ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => RequestCommitWatermark::PRIORITY]);
        }

        $services->set($prefix . 'write_unit', WriteUnit::class)
            ->args([
                service('doctrine'),
                inline_service(WriteUnitPolicy::class)->args([
                    $config['max_attempts'],
                    $config['backoff']['floor_ms'],
                    $config['backoff']['base_ms'],
                    $config['backoff']['cap_ms'],
                    $config['replay_on'],
                    $conflict['retry_after'],
                ]),
                service($prefix . 'state_repairer'),
                service('clock')->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
                null,
                $config['commit_detection'] ? service($prefix . 'commit_ledger') : null,
                tagged_iterator(ResetBetweenAttemptsInterface::TAG),
                service('event_dispatcher')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'transaction_doctrine'])
            ->public();
        $services->alias(WriteUnitInterface::class, $prefix . 'write_unit');
        $services->alias(WriteUnit::class, $prefix . 'write_unit');

        if ($config['controller_attribute'] || $config['controller_routes'] !== []) {
            $services->set($prefix . 'write_unit_controller_listener', WriteUnitControllerListener::class)
                ->args([
                    service($prefix . 'write_unit'),
                    service('doctrine'),
                    service('security.token_storage')->nullOnInvalid(),
                    $config['controller_routes'],
                    $config['controller_attribute'],
                ])
                ->tag('kernel.event_listener', ['event' => KernelEvents::CONTROLLER_ARGUMENTS, 'priority' => WriteUnitControllerListener::PRIORITY]);
        }

        $messenger = interface_exists(MiddlewareInterface::class);
        if ($config['messenger_middleware'] && $messenger) {
            $services->set($prefix . 'write_unit_middleware', WriteUnitMiddleware::class)
                ->args([service($prefix . 'write_unit')])
                ->public();
            $services->alias(WriteUnitMiddleware::class, $prefix . 'write_unit_middleware');
        }

        $builder->registerForAutoconfiguration(ResetBetweenAttemptsInterface::class)->addTag(ResetBetweenAttemptsInterface::TAG);
        $builder->registerForAutoconfiguration(ConflictExceptionMapperInterface::class)->addTag(ConflictExceptionMapperInterface::TAG);
        $builder->registerForAutoconfiguration(ConflictResponseFormatterInterface::class)->addTag(ConflictResponseFormatterInterface::TAG);

        if ($conflict['enabled']) {
            if ($conflict['map_transient_failures']) {
                $services->set($prefix . 'transient_failure_conflict_mapper', TransientFailureConflictMapper::class)
                    ->tag(ConflictExceptionMapperInterface::TAG);
            }

            $services->set($prefix . 'conflict_responder', ConcurrencyConflictResponder::class)
                ->args([
                    service($prefix . 'conflict_response_policy'),
                    tagged_iterator(ConflictExceptionMapperInterface::TAG),
                    tagged_iterator(ConflictResponseFormatterInterface::TAG),
                    service('twig')->nullOnInvalid(),
                    service('translator')->nullOnInvalid(),
                    service('logger')->nullOnInvalid(),
                    $conflict['template'],
                    $conflict['json_error'],
                    $conflict['json_path_prefixes'],
                ])
                ->tag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'priority' => 64])
                ->tag('monolog.logger', ['channel' => 'transaction_doctrine']);
        }

        if ($config['worker_hygiene']) {
            $hygiene = $services->set($prefix . 'worker_connection_hygiene', WorkerConnectionHygiene::class)
                ->args([service($prefix . 'state_repairer')])
                ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => 4096])
                ->tag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'method' => 'onException', 'priority' => 4096])
                ->tag('kernel.event_listener', ['event' => KernelEvents::TERMINATE, 'method' => 'onTerminate', 'priority' => -4096]);
            if ($messenger) {
                $hygiene->tag('kernel.event_listener', ['event' => WorkerMessageFailedEvent::class, 'method' => 'onMessageFailed', 'priority' => -4096]);
            }
        }

        $builder->setParameter($prefix . 'conflict_response.status', $conflict['status']);
        $builder->setParameter($prefix . 'conflict_response.retry_after', $conflict['retry_after']);
    }
}
