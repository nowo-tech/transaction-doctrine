<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * A row whose identifier is its owner (derived identity; audit VQ6-1, probe Q3).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_derived')]
class Derived
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: Owner::class)]
        #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false)]
        public Owner $owner,
        #[ORM\Column(type: 'string', length: 50)]
        public string $label = '',
    ) {
    }
}
