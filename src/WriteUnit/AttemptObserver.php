<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;

use function spl_object_id;

/**
 * What one attempt of a write unit did to an open entity manager that already managed entities before the unit (the
 * host's, 1.8, audits VD2-4 / VQ): the entities it kept, which of them the attempt hydrated again (postLoad), whether
 * a flush through the manager wrote during the attempt (onFlush) and which kept entities such a flush deleted (with
 * their identifier and original data, to manage them again once the rollback restored their rows).
 *
 * One instance per manager per run(): listening on the manager's event manager for the duration of the run, detached in
 * finally, and start() between two attempts. Nothing outlives the call (FrankenPHP worker mode, kernel reset off).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class AttemptObserver
{
    /** @var array<int, object> Entities hydrated during the attempt, by spl_object_id */
    public array $loaded = [];

    /** Whether a flush through the manager wrote during the attempt. */
    public bool $flushed = false;

    /** @var list<array{object, array<string, mixed>, array<string, mixed>}> Kept entities a flush deleted: entity, identifier, original data */
    public array $deleted = [];

    /** @var array<int, true> Kept entities a flush of the attempt updated, deleted or whose collection it wrote, by spl_object_id */
    public array $touched = [];

    /**
     * The manager's unit of work when the run started. A manager reset in place (DoctrineBundle keeps the same lazy
     * service object and builds a new entity manager behind it) has a new one: nothing of the old one is kept (1.8.1).
     */
    public readonly UnitOfWork $unitOfWork;

    /**
     * The identifiers of the kept entities when the run started, by spl_object_id: what a re-attach into a reset or
     * cleared manager registers them under (1.8.2).
     *
     * @var array<int, array<string, mixed>>
     */
    public readonly array $identifiers;

    /**
     * @param EntityManagerInterface $manager The manager observed
     * @param array<int, object> $kept The entities it managed before the first attempt, by spl_object_id
     * @param array<int, array<string, mixed>>|null $identifiers Their identifiers; null = read from the manager now
     */
    public function __construct(
        public readonly EntityManagerInterface $manager,
        public readonly array $kept,
        ?array $identifiers = null,
    ) {
        $this->unitOfWork = $manager->getUnitOfWork();
        if ($identifiers === null) {
            $identifiers = [];
            foreach ($kept as $oid => $entity) {
                /** @var array<string, mixed> $identifier */
                $identifier        = $this->unitOfWork->getEntityIdentifier($entity);
                $identifiers[$oid] = $identifier;
            }
        }

        $this->identifiers = $identifiers;
    }

    /** Starts over for the next attempt. */
    public function start(): void
    {
        $this->loaded  = [];
        $this->flushed = false;
        $this->deleted = [];
        $this->touched = [];
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $entity                               = $args->getObject();
        $this->loaded[spl_object_id($entity)] = $entity;
    }

    /** A flush is about to write: the kept entities it deletes are recorded before Doctrine forgets them. */
    public function onFlush(OnFlushEventArgs $args): void
    {
        // DoctrineBundle shares one event manager between the entity managers of a connection.
        if ($args->getObjectManager() !== $this->manager) {
            return;
        }

        $unitOfWork = $this->manager->getUnitOfWork();
        // An empty flush (it still dispatches onFlush) writes nothing.
        $this->flushed = $this->flushed || $unitOfWork->getScheduledEntityInsertions() !== [] || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== [] || $unitOfWork->getScheduledCollectionUpdates() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== [];
        $written = [...$unitOfWork->getScheduledEntityUpdates(), ...$unitOfWork->getScheduledEntityDeletions()];
        foreach ([...$unitOfWork->getScheduledCollectionUpdates(), ...$unitOfWork->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if ($owner !== null) {
                $written[] = $owner;
            }
        }

        foreach ($written as $entity) {
            if (isset($this->kept[spl_object_id($entity)])) {
                $this->touched[spl_object_id($entity)] = true;
            }
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if (isset($this->kept[spl_object_id($entity)])) {
                /** @var array<string, mixed> $identifier */
                $identifier      = $unitOfWork->getEntityIdentifier($entity);
                $this->deleted[] = [$entity, $identifier, $unitOfWork->getOriginalEntityData($entity)];
            }
        }
    }
}
