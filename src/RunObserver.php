<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;

use function spl_object_id;

/**
 * What the entity manager did while one run() was in progress: whether it flushed, and which entities were hydrated
 * (postLoad fires for a new entity, a proxy initialisation and a refresh() alike, so every entity whose state came
 * from the run's transaction is seen). One instance per run: attached to the manager's event manager for the
 * duration of the run and detached in finally, so nothing outlives the call.
 *
 * @internal
 */
final class RunObserver
{
    public bool $flushed = false;

    /** @var array<int, object> Entities hydrated during the run, by spl_object_id */
    public array $loaded = [];

    public function postFlush(): void
    {
        $this->flushed = true;
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $entity                               = $args->getObject();
        $this->loaded[spl_object_id($entity)] = $entity;
    }
}
