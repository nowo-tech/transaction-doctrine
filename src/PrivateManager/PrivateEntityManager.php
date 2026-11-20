<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Closure;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query\Filter\Parameter;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_keys;
use function array_sum;
use function is_array;
use function is_string;
use function ksort;
use function sprintf;

/**
 * A bundle's private entity manager on the application's connection, zero-config (1.9, REQ-ARCH-001 rule 6, plan
 * prerequisite P3): the shared shape of the copies TaskBoard, Wiki, ChatKit and Vault ship.
 *
 * The bundle's repositories and services write through this manager instead of the application's: a bundle flush never
 * commits a change the application left pending, and a failed bundle write closes only this manager. It is created on
 * the connection, configuration (mapping, metadata cache, proxies; a repository factory of its own) and event manager
 * (the application's listeners) of a SOURCE manager of the application, named by the bundle's `entity_manager` setting
 * (default `default`). The tables do not move and the connection is shared, so a transaction the application opened
 * around a bundle call includes the bundle's rows (its flushes then use savepoints).
 *
 * The object is a stable decorator (repositories keep it); the wrapped manager is resolved on every call and created
 * again when:
 *
 * - it was closed (its own failed flush) or the source connection changed;
 * - a rollback (transaction or savepoint) undid rows it wrote or loaded inside a transaction, so its identity map may
 *   describe rows that no longer exist (told by PrivateEntityManagerMiddleware, DBAL 4 only; on DBAL 3 only the cases
 *   above and the boundary apply). A rollback of a level it did not write in leaves it alone. A stale manager that still
 *   has scheduled work is kept (replacing it would silently drop that work) until the work is flushed or the boundary;
 * - reset() ran: at every unit-of-work boundary (the Symfony bundle's UnitOfWorkBoundaryListener resets every service
 *   tagged `nowo_transaction_doctrine.private_entity_manager`: start and end of the main request, of a Messenger message
 *   and of the outermost console command, worker mode with kernel reset off included) and at `kernel.reset`. Changes
 *   still pending then are dropped and logged as a warning (entity classes and counts, never a value).
 *
 * The SQL filters enabled on the source, with their parameters, are mirrored on every call; a filter whose parameters
 * changed is re-created, so a parameter the source no longer has (a previous tenant) never survives.
 *
 * The application's managers are never flushed, cleared, closed or reset by this class.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PrivateEntityManager extends EntityManagerDecorator implements ResetInterface, TransactionLevelListenerInterface
{
    /** The service tag the Symfony bundle resets at every unit-of-work boundary. */
    public const TAG = 'nowo_transaction_doctrine.private_entity_manager';

    private ?EntityManagerInterface $current = null;

    /** A rollback undid rows the current private manager wrote or loaded. */
    private bool $stale = false;

    /**
     * Nesting levels of the connection in which the private manager wrote (flush, noteWrite()) or loaded rows nobody
     * committed yet: only a rollback of one of these levels makes its identity map stale.
     *
     * @var array<int, true>
     */
    private array $uncommitted = [];

    /**
     * @param ManagerRegistry $registry The application's registry (the source manager is resolved per call)
     * @param string $sourceManagerName The application's manager whose connection, configuration and events are shared
     * @param class-string|null $mappedClass An entity class the source must map (checked by source()); null = no check
     * @param string $label Who owns the manager, in log messages (for example "NowoWikiBundle")
     * @param LoggerInterface|null $logger Warns when a boundary drops pending changes
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $sourceManagerName = 'default',
        private readonly ?string $mappedClass = null,
        private readonly string $label = 'bundle',
        private readonly ?LoggerInterface $logger = null,
    ) {
        // No wrapped manager yet: every read of $this->wrapped goes through __get() (resolved per call).
        unset($this->{'wrapped'});
    }

    /**
     * The wrapped manager of EntityManagerDecorator (the only magic property).
     */
    public function __get(string $name): EntityManagerInterface
    {
        if ($name !== 'wrapped') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }

        return $this->live();
    }

    /**
     * The open private manager, created (again) when needed, with the source's SQL filters.
     */
    public function live(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->current;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen() || $current->getConnection() !== $source->getConnection()
            || ($this->stale && !$this->hasScheduledWork($current))) {
            if ($current instanceof EntityManagerInterface && $current->isOpen()) {
                $current->clear();
            }

            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager  = $source->getEventManager();
            $configuration = clone $source->getConfiguration();
            $configuration->setRepositoryFactory(new PrivateManagerRepositoryFactory($configuration->getRepositoryFactory()));
            $current = $this->current = new EntityManager($source->getConnection(), $configuration, $eventManager instanceof EventManager ? $eventManager : null); // @igor-ignore — the bundle's own unit of work, dropped at every boundary
            // Idempotent (keyed by listener object): only events of the private manager are counted (see postFlush()).
            $current->getEventManager()->addEventListener([Events::postFlush, Events::postLoad], $this);
            $this->stale       = false; // @igor-ignore — reset with the manager
            $this->uncommitted = []; // @igor-ignore — reset with the manager
        }

        $this->syncFilters($source, $current);

        return $current;
    }

    /** Whether a private manager exists right now (no side effect). */
    public function isCreated(): bool
    {
        return $this->current instanceof EntityManagerInterface;
    }

    /** Whether a rollback made the private manager's identity map stale (no side effect). */
    public function isStale(): bool
    {
        return $this->stale;
    }

    public function connectionRolledBack(?int $level = 1): void
    {
        $undone = false;
        foreach (array_keys($this->uncommitted) as $written) {
            if ($level === null || $written >= $level) {
                $undone = true;
                unset($this->uncommitted[$written]); // @igor-ignore — the private manager's open levels
            }
        }

        if ($undone && $this->current instanceof EntityManagerInterface) {
            $this->stale = true; // @igor-ignore — cleared when the manager is replaced
        }
    }

    public function connectionCommitted(): void
    {
        $this->uncommitted = []; // @igor-ignore — the private manager's open levels
    }

    public function savepointReleased(int $level): void
    {
        if (isset($this->uncommitted[$level])) {
            unset($this->uncommitted[$level]); // @igor-ignore — the private manager's open levels
            if ($level > 1) {
                $this->uncommitted[$level - 1] = true; // @igor-ignore — the private manager's open levels
            }
        }
    }

    /** A statement of the bundle that bypassed the unit of work (bulk DQL, DBAL) wrote at the current level. */
    public function noteWrite(): void
    {
        $level = $this->current?->getConnection()->getTransactionNestingLevel() ?? 0;
        if ($level > 0) {
            $this->uncommitted[$level] = true; // @igor-ignore — the private manager's open levels
        }
    }

    /**
     * Doctrine `postFlush` listener: a flush of the private manager wrote at the current level. Other managers' events
     * (the event manager is the source's, shared) are ignored.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        $this->noteOwn($args->getObjectManager());
    }

    /**
     * Doctrine `postLoad` listener: rows the private manager loaded inside a transaction may be someone's uncommitted
     * writes, so a rollback of that level makes the identity map stale too.
     *
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $this->noteOwn($args->getObjectManager());
    }

    /**
     * Drops the private manager (a unit-of-work boundary or `kernel.reset`); the next call creates a fresh one. Changes
     * still pending are lost: they are logged as a warning with their entity classes and counts.
     */
    public function reset(): void
    {
        $current = $this->current;
        // Dropped first, so a failure below can never keep a manager across the boundary.
        $this->current     = null; // @igor-ignore — the boundary drop itself
        $this->stale       = false; // @igor-ignore — the boundary drop itself
        $this->uncommitted = []; // @igor-ignore — the boundary drop itself
        if (!$current instanceof EntityManagerInterface || !$current->isOpen()) {
            return;
        }

        try {
            $this->warnAboutDroppedChanges($current);
        } catch (Throwable $exception) {
            // Computing the change sets can throw (an invalid association, a detached or half-built entity).
            $this->logGenericWarning($exception);
        }

        try {
            $current->clear();
        } catch (Throwable $exception) {
            // A host onClear listener that throws must not abort the boundary: the manager is dropped anyway.
            $this->logGenericWarning($exception);
        }
    }

    /**
     * The application's manager whose connection, configuration, events and filters the private one shares. Never
     * flushed, cleared, closed or reset here.
     *
     * @throws LogicException When it is not an ORM manager, or does not map the expected class
     */
    public function source(): EntityManagerInterface
    {
        $source = $this->registry->getManager($this->sourceManagerName);
        if (!$source instanceof EntityManagerInterface || ($this->mappedClass !== null && $source->getMetadataFactory()->isTransient($this->mappedClass))) {
            throw new LogicException(sprintf('The Doctrine entity manager "%s" (source of the %s private entity manager) is not an ORM manager mapping %s.', $this->sourceManagerName, $this->label, $this->mappedClass ?? 'the bundle\'s entities'));
        }

        return $source;
    }

    private function noteOwn(ObjectManager $manager): void
    {
        if ($this->current instanceof EntityManagerInterface && $manager === $this->current) {
            $this->noteWrite();
        }
    }

    private function logGenericWarning(Throwable $exception): void
    {
        try {
            $this->logger?->warning('{bundle}: the private entity manager was dropped at a unit-of-work boundary; its pending changes could not be inspected or cleared ({exception}).', [
                'bundle'    => $this->label,
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // Nothing else to report to: the manager is dropped anyway.
        }
    }

    /** A boundary that drops the private manager with inserts, updates or deletes still waiting logs them, per class. */
    private function warnAboutDroppedChanges(EntityManagerInterface $manager): void
    {
        if (!$this->logger instanceof LoggerInterface) {
            return;
        }

        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $changes = [];
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                $class           = PrivateManagerRegistry::realClass($entity::class);
                $changes[$class] = ($changes[$class] ?? 0) + 1;
            }
        }

        if ($changes === []) {
            return;
        }

        ksort($changes);
        $this->logger->warning('{bundle}: {count} pending change(s) of the private entity manager were dropped at a unit-of-work boundary; the application\'s flush() does not write them.', [
            'bundle'  => $this->label,
            'count'   => array_sum($changes),
            'changes' => $changes,
        ]);
    }

    private function hasScheduledWork(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== [];
    }

    /**
     * Mirrors the SQL filters enabled on the source, with their parameters, and disables the others. A filter whose
     * parameters differ (its state string) is disabled and enabled again (a fresh instance) before its parameters are
     * copied. The parameters are read as stored: values, list flag and type are kept (ORM 2 arrays and ORM 3 objects).
     */
    private function syncFilters(EntityManagerInterface $source, EntityManagerInterface $target): void
    {
        $wanted  = $source->getFilters()->getEnabledFilters();
        $filters = $target->getFilters();
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            if (!isset($wanted[$name])) {
                $filters->disable($name);
            }
        }

        foreach ($wanted as $name => $filter) {
            $class = $filter::class;
            if ($target->getConfiguration()->getFilterClassName($name) !== $class) {
                if ($filters->isEnabled($name)) {
                    $filters->disable($name);
                }

                $target->getConfiguration()->addFilter($name, $class);
            }

            $state = (string) $filter;
            if ($filters->isEnabled($name)) {
                if ((string) $filters->getFilter($name) === $state) {
                    continue;
                }

                $filters->disable($name);
            }

            $copy = $filters->enable($name);
            foreach ($this->filterParameters($filter) as $parameter => [$value, $type, $isList]) {
                if ($isList) {
                    /** @var array<mixed> $list */
                    $list = is_array($value) ? $value : [$value];
                    $copy->setParameterList($parameter, $list, is_string($type) ? $type : Types::STRING);
                } else {
                    $copy->setParameter($parameter, $value, is_string($type) ? $type : null);
                }
            }
        }
    }

    /**
     * The raw parameters of a filter (SQLFilter keeps them private): value, type and list flag.
     *
     * @return array<string, array{mixed, mixed, bool}>
     */
    private function filterParameters(SQLFilter $filter): array
    {
        /** @var array<string, mixed> $raw */
        $raw = Closure::bind(static fn (SQLFilter $filter): array => $filter->parameters, null, SQLFilter::class)($filter);

        $parameters = [];
        foreach ($raw as $name => $parameter) {
            if ($parameter instanceof Parameter) {
                // ORM 3: Doctrine\ORM\Query\Filter\Parameter (instanceof an unknown class is false on ORM 2).
                $parameters[$name] = [$parameter->value, $parameter->type, $parameter->isList];
            } elseif (is_array($parameter)) {
                // ORM 2: ['value' => …, 'type' => …, 'is_list' => …].
                $parameters[$name] = [$parameter['value'] ?? null, $parameter['type'] ?? null, ($parameter['is_list'] ?? false) === true];
            }
        }

        return $parameters;
    }
}
