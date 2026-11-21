<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * The inverse side of a one-to-one with orphan removal (audit XD-1): setting $badge to null deletes the Badge's row at
 * the flush, and nothing but UnitOfWork's orphan removals says so.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_profile')]
class Profile
{
    #[ORM\OneToOne(targetEntity: Badge::class, mappedBy: 'profile', orphanRemoval: true)]
    public ?Badge $badge = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
    ) {
    }
}
