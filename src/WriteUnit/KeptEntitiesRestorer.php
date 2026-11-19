<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\UnitOfWork;
use Throwable;

use function array_diff_key;
use function array_key_exists;
use function is_object;
use function spl_object_id;

/**
 * Between two attempts of a write unit, brings an open entity manager that already managed entities before the
 * unit's first attempt (the host's, typically) back to that state WITHOUT clearing it (1.8, audit VD2-4,
 * REQ-RUNTIME-003 rule 2: an open host entity manager is never cleared or reset):
 *
 * - every entity the failed attempt brought in (loaded, referenced or persisted) is detached, without cascading into
 *   the kept entities;
 * - a kept entity the attempt changed (a field, a to-one, a collection, or a link to a brought entity) or re-hydrated
 *   (refresh(), a proxy initialised: its state came from the rolled-back transaction) is refreshed from the database;
 *   the run started without pending changes, so nothing of the caller's is lost;
 * - a kept entity the attempt removed or detached, or one whose refresh fails, cannot be brought back: restore()
 *   answers false and the write unit gives up (ConcurrencyConflict) instead of clearing the manager.
 *
 * Reads only loaded state to decide (no change-set computation, which would cascade-persist); the refresh issues one
 * query per changed entity. Stateless.
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
     * @param array<int, object> $kept The entities the manager managed before the unit's first attempt
     * @param array<int, object> $reloaded The entities hydrated during the failed attempt (RunObserver)
     *
     * @return bool Whether every kept entity is managed again with its database state
     */
    public static function restore(EntityManagerInterface $manager, array $kept, array $reloaded): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $brought    = array_diff_key(self::managed($manager), $kept);
        // UnitOfWork::detach() follows cascade: detach into any entity; doDetach() with the kept entities already
        // "visited" stops there. Same private signature in ORM 2.20 and 3.x (DoctrineTransactionRunner does the same).
        $detach = Closure::bind(static function (UnitOfWork $unitOfWork, object $entity, array $visited): void {
            $unitOfWork->doDetach($entity, $visited);
        }, null, UnitOfWork::class);
        foreach ($brought as $entity) {
            $detach($unitOfWork, $entity, $kept);
        }

        foreach ($kept as $oid => $entity) {
            if (!$manager->contains($entity)) {
                return false;
            }

            if ($unitOfWork->isUninitializedObject($entity)
                || (!isset($reloaded[$oid]) && !self::differs($manager, $entity, $brought))) {
                continue;
            }

            try {
                $manager->refresh($entity);
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a kept entity differs from its original data, or links to a brought (now detached) entity.
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

            if ((array_key_exists($field, $original) && $value !== $original[$field])
                || (is_object($value) && isset($brought[spl_object_id($value)]))
                || ($value instanceof PersistentCollection && ($value->isDirty() || self::holdsBrought($value, $brought)))) {
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
        // unwrap(): never loads an uninitialized collection.
        foreach ($collection->unwrap()->toArray() as $element) {
            if (is_object($element) && isset($brought[spl_object_id($element)])) {
                return true;
            }
        }

        return false;
    }
}
