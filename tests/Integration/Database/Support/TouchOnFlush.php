<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Event\OnFlushEventArgs;

use function property_exists;

/**
 * An onFlush listener that touches every updated entity with a label and recomputes its change set, as a host's
 * "updated at / updated by" listener does (1.9.3): ORM's recomputeSingleEntityChangeSet() then leaves the entity's
 * collections out of its loaded state.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TouchOnFlush
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $manager    = $args->getObjectManager();
        $unitOfWork = $manager->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (property_exists($entity, 'label')) {
                $entity->label .= '+';
                $unitOfWork->recomputeSingleEntityChangeSet($manager->getClassMetadata($entity::class), $entity);
            }
        }
    }
}
