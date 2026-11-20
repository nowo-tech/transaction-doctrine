<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use RuntimeException;
use Throwable;

use function array_combine;
use function array_diff_key;
use function array_key_exists;
use function is_object;
use function spl_object_id;

/**
 * Between two attempts of a write unit (after the unit's rollback), brings an open entity manager that already managed
 * entities before the unit's first attempt (the host's, typically) back to what the database holds, WITHOUT clearing it
 * (1.8, audits VD2-4 / VQ, REQ-RUNTIME-003 rule 2: an open host entity manager is never cleared or reset).
 *
 * The unit only starts when no manager holds pending work, so everything pending or changed now is the failed attempt's.
 * Every step runs, whatever an earlier one could not do (audit VQ-3), in this order:
 *
 * 1. kept entities a flush of the attempt deleted are managed again (the rollback restored their rows);
 * 2. what the attempt scheduled and did not flush is forgotten: removals of kept entities cancelled (managed again, no
 *    cascade), orphan removals cancelled (audit VQ-2: removeElement() / clear() on an orphanRemoval collection), and
 *    computed entity / collection updates dropped;
 * 3. what the attempt brought in (loaded, referenced, persisted) is unlinked from the kept entities (their to-one
 *    associations get their original value, their collections lose it) and detached without cascading into them;
 * 4. kept entities are reloaded from the database through their persister, never EntityManager::refresh(), which
 *    cascades first and stops at a detached entity (audit VQ-4): every initialised one when a flush wrote during the
 *    attempt (audit VQ-1: a flush moves the original data to the rolled-back values, so nothing would look changed;
 *    a version would be stale), else the ones the attempt touched: changed (also behind a computeChangeSets() inside
 *    the attempt, audit VQ2-3), re-hydrated, removed, or written by one of its flushes. A reload brings back fields,
 *    version, to-one associations and fresh (uninitialised) collections.
 *
 * restore() answers false when a kept entity could not be brought back (the attempt detached it, or a step threw for an
 * entity the attempt touched: its row gone, a failing reload; an untouched kept entity whose row another session
 * deleted is left as the host held it, audit VQ2-2): the write unit then gives up with ConcurrencyConflict instead of replaying, and the
 * manager keeps whatever could be restored, still open and never cleared.
 *
 * Stateless: every call works on the manager it is given.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class KeptEntitiesRestorer
{
    /**
     * @return array<int, object> The entities the manager manages (proxies included) or will insert, by spl_object_id
     */
    public static function managed(EntityManagerInterface $manager): array
    {
        $unitOfWork = $manager->getUnitOfWork();
        $entities   = [];

        foreach ($unitOfWork->getIdentityMap() as $byId) {
            foreach ($byId as $entity) {
                $entities[spl_object_id($entity)] = $entity;
            }
        }

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            $entities[spl_object_id($entity)] = $entity;
        }

        return $entities;
    }

    /**
     * @param bool $everything Reload every initialised kept entity (an abandoned replay: the pending-work check may have
     *                         computed change sets, which moves the original data)
     *
     * @return bool Whether every kept entity is managed again with its database state
     */
    public static function restore(AttemptObserver $attempt, bool $everything = false): bool
    {
        $manager    = $attempt->manager;
        $unitOfWork = $manager->getUnitOfWork();
        $kept       = $attempt->kept;
        $restored   = true;

        // 1. Deleted by a flush of the attempt: the rollback restored the rows.
        foreach ($attempt->deleted as [$entity, $identifier, $data]) {
            $restored = self::safely(static function () use ($manager, $unitOfWork, $entity, $identifier, $data): void {
                if (!$unitOfWork->isInIdentityMap($entity)
                    && $unitOfWork->tryGetById($identifier, $manager->getClassMetadata($entity::class)->rootEntityName) === false) {
                    $unitOfWork->registerManaged($entity, $identifier, $data);
                }
            }) && $restored;
        }

        // 2. What the attempt scheduled without flushing. Decide what to reload first: the unlinking below changes it.
        // An entity the attempt loaded itself and removed (audit VQ2-1) is brought too, whether or not it is still in the
        // identity map.
        $brought = array_diff_key(self::managed($manager) + self::scheduledDeletions($unitOfWork), $kept);
        $touched = [];
        foreach ($kept as $oid => $entity) {
            // computeChangeSets() inside the attempt moves the original data, hiding the change (audit VQ2-3).
            if (isset($attempt->loaded[$oid]) || isset($attempt->touched[$oid]) || $unitOfWork->isScheduledForDelete($entity)
                || $unitOfWork->getEntityChangeSet($entity) !== []
                || ($unitOfWork->isInIdentityMap($entity) && !$unitOfWork->isUninitializedObject($entity) && self::differs($manager, $entity, $brought))) {
                $touched[$oid] = true;
            }
        }

        self::forgetScheduledWork($unitOfWork, $kept);

        // 3. What the attempt brought in.
        foreach ($kept as $entity) {
            if ($unitOfWork->isInIdentityMap($entity) && !$unitOfWork->isUninitializedObject($entity)) {
                $restored = self::safely(static fn () => self::unlink($manager, $entity, $brought)) && $restored;
            }
        }

        $detach = Closure::bind(static function (UnitOfWork $unitOfWork, object $entity, array $visited): void {
            $unitOfWork->doDetach($entity, $visited);
        }, null, UnitOfWork::class);
        foreach ($brought as $entity) {
            $restored = self::safely(static fn () => $detach($unitOfWork, $entity, $kept)) && $restored;
        }

        // 4. Back to what the database holds.
        foreach ($kept as $oid => $entity) {
            if (!$unitOfWork->isInIdentityMap($entity)) {
                $restored = false; // detached by the attempt: its original data is gone

                continue;
            }

            if ($unitOfWork->isUninitializedObject($entity) || (!isset($touched[$oid]) && !$everything && !$attempt->flushed)) {
                continue;
            }

            // After a flush every kept entity is reloaded, but only one the attempt touched counts when its reload fails:
            // an untouched entity whose row another session deleted stays as the host held it (audit VQ2-2).
            $reloaded = self::safely(static fn () => self::reload($manager, $entity));
            $restored = ($reloaded || !isset($touched[$oid])) && $restored;
        }

        return $restored;
    }

    /**
     * Whether a kept entity differs from its original data (a field, a to-one association, a to-many collection that is
     * dirty or no longer the managed one) or holds a brought entity (a to-one, or a collection the attempt loaded or
     * added to). Reads only (no change-set computation, no collection load).
     *
     * @param array<int, object> $brought
     */
    private static function differs(EntityManagerInterface $manager, object $entity, array $brought): bool
    {
        $metadata = $manager->getClassMetadata($entity::class);
        $original = $manager->getUnitOfWork()->getOriginalEntityData($entity);

        foreach ($metadata->getFieldNames() as $field) {
            if (array_key_exists($field, $original) && $metadata->getFieldValue($entity, $field) !== $original[$field]) {
                return true;
            }
        }

        foreach ($metadata->getAssociationNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);
            if ($metadata->isCollectionValuedAssociation($field)
                ? !$value instanceof PersistentCollection || $value->isDirty() || self::holdsBrought($value, $brought)
                    // clear() on an inverse side leaves it clean but emptied (audit VQ-2): compare with the snapshot.
                    || $value->getDeleteDiff() !== [] || $value->getInsertDiff() !== []
                : (array_key_exists($field, $original) && $value !== $original[$field]) || (is_object($value) && isset($brought[spl_object_id($value)]))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param PersistentCollection<array-key, mixed> $collection
     * @param array<int, object> $brought
     */
    private static function holdsBrought(PersistentCollection $collection, array $brought): bool
    {
        foreach ($collection->unwrap()->toArray() as $element) {
            if (is_object($element) && isset($brought[spl_object_id($element)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, object> The entities scheduled for removal, by spl_object_id
     */
    private static function scheduledDeletions(UnitOfWork $unitOfWork): array
    {
        $entities = [];
        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            $entities[spl_object_id($entity)] = $entity;
        }

        return $entities;
    }

    /**
     * Drops the attempt's unflushed work from the unit of work: kept entities scheduled for removal are managed again
     * (no cascade), any other scheduled removal forgotten entirely (forgetRemovals()), orphan removals cancelled,
     * computed updates and collection work forgotten. Nothing of it existed before the unit (it only starts without
     * pending work). Same private properties in ORM 2.20 and 3.x.
     *
     * @param array<int, object> $kept
     */
    private static function forgetScheduledWork(UnitOfWork $unitOfWork, array $kept): void
    {
        self::forgetRemovals($unitOfWork, $kept, true);
        Closure::bind(static function (UnitOfWork $unitOfWork): void {
            $unitOfWork->orphanRemovals      = [];
            $unitOfWork->entityUpdates       = [];
            $unitOfWork->entityChangeSets    = [];
            $unitOfWork->collectionUpdates   = [];
            $unitOfWork->collectionDeletions = [];
        }, null, UnitOfWork::class)($unitOfWork);
    }

    /**
     * Forgets every scheduled removal of an entity not in $kept entirely: remove() keeps the identity-map entry, so it
     * is dropped first (removeFromIdentityMap() reads the identifier), then the removal, state, identifier and original
     * data, so the entity is detached (audits VQ2-1, VQ3-1, VQ4-1: doDetach() does nothing for a REMOVED entity, and a
     * later flush would delete its row). A removal of a kept entity is cancelled (managed again, no cascade) when
     * $restoreKept is true, else left as it is. Same private properties in ORM 2.20 and 3.x.
     *
     * @param array<int, object> $kept The entities to keep, by spl_object_id
     *
     * @return array<int, object> The entities forgotten, by spl_object_id
     */
    public static function forgetRemovals(UnitOfWork $unitOfWork, array $kept, bool $restoreKept): array
    {
        return Closure::bind(static function (UnitOfWork $unitOfWork, array $kept, bool $restoreKept): array {
            $forgotten = [];
            foreach ($unitOfWork->entityDeletions as $oid => $entity) {
                if (isset($kept[$oid])) {
                    if ($restoreKept) {
                        unset($unitOfWork->entityDeletions[$oid]);
                        $unitOfWork->entityStates[$oid] = UnitOfWork::STATE_MANAGED;
                        $unitOfWork->addToIdentityMap($entity);
                    }

                    continue;
                }

                $unitOfWork->removeFromIdentityMap($entity);
                unset($unitOfWork->entityDeletions[$oid], $unitOfWork->entityStates[$oid], $unitOfWork->entityIdentifiers[$oid], $unitOfWork->originalEntityData[$oid]);
                $forgotten[$oid] = $entity;
            }

            return $forgotten;
        }, null, UnitOfWork::class)($unitOfWork, $kept, $restoreKept);
    }

    /**
     * Removes the brought entities from one kept entity's associations: a to-one pointing at one gets its original value
     * back, a collection loses them (unwrap(): no orphan removal scheduled, no load of an uninitialised collection).
     *
     * @param array<int, object> $brought
     */
    private static function unlink(EntityManagerInterface $manager, object $entity, array $brought): void
    {
        $metadata = $manager->getClassMetadata($entity::class);
        $original = $manager->getUnitOfWork()->getOriginalEntityData($entity);

        foreach ($metadata->getAssociationNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);

            if ($value instanceof PersistentCollection) {
                $elements = $value->unwrap();
                foreach ($elements->toArray() as $key => $element) {
                    if (is_object($element) && isset($brought[spl_object_id($element)])) {
                        $elements->remove($key);
                    }
                }

                continue;
            }

            if (is_object($value) && isset($brought[spl_object_id($value)])) {
                $previous = $original[$field] ?? null;
                $metadata->setFieldValue($entity, $field, is_object($previous) && isset($brought[spl_object_id($previous)]) ? null : $previous);
            }
        }
    }

    /**
     * Reloads a managed entity from the database through its persister (load() with the entity: Doctrine's refresh
     * hints): fields, version, to-one associations and fresh to-many collections come back, with no cascade. Only
     * postLoad runs.
     *
     * @throws RuntimeException When the database no longer has the row
     */
    private static function reload(EntityManagerInterface $manager, object $entity): void
    {
        $unitOfWork = $manager->getUnitOfWork();
        $metadata   = $manager->getClassMetadata($entity::class);
        $criteria   = array_combine($metadata->getIdentifierFieldNames(), $unitOfWork->getEntityIdentifier($entity));
        if ($unitOfWork->getEntityPersister($metadata->name)->load($criteria, $entity) === null) {
            throw new RuntimeException('The row of a kept entity is gone.');
        }
    }

    /**
     * Runs one restore step; a failure skips that step only.
     *
     * @return bool Whether the step completed
     */
    private static function safely(callable $step): bool
    {
        try {
            $step();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
