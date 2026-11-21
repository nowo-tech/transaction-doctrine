<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Closure;
use Doctrine\ORM\UnitOfWork;

/**
 * The entities orphan removal deletes at the next flush. UnitOfWork keeps them in a private property with no getter
 * (same name in ORM 2.20 and 3.x): `clear()` or `removeElement()` on an orphanRemoval collection, or an inverse
 * one-to-one with orphanRemoval set to null (after computeChangeSets()), schedules them there and nowhere else.
 *
 * Only managed orphans are returned: the flush calls remove() on each scheduled orphan, which does nothing for one that
 * is no longer managed. That happens after the host flushed an inverse one-to-one set to null: ORM keeps the deleted
 * entity in the owner's original data (an inverse-side change alone does not refresh it), so every later
 * computeChangeSets() schedules it again although no flush deletes anything.
 *
 * Read only: no change-set computation, nothing scheduled or cleared, no database lookup (the entity state is read
 * with STATE_NEW assumed for an unknown entity).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class OrphanRemovals
{
    /**
     * @return list<object>
     */
    public static function pending(UnitOfWork $unitOfWork): array
    {
        /** @var array<object> $orphans */
        $orphans = Closure::bind(static fn (UnitOfWork $unitOfWork): array => $unitOfWork->orphanRemovals, null, UnitOfWork::class)($unitOfWork);

        $pending = [];
        foreach ($orphans as $orphan) {
            if ($unitOfWork->getEntityState($orphan, UnitOfWork::STATE_NEW) === UnitOfWork::STATE_MANAGED) {
                $pending[] = $orphan;
            }
        }

        return $pending;
    }
}
