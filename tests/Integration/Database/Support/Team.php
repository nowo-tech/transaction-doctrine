<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The owning side of a two-sided many-to-many (1.9.3: Member is its inverse side, which the flush never writes).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_team')]
class Team
{
    /** @var Collection<int, Member> */
    #[ORM\ManyToMany(targetEntity: Member::class, inversedBy: 'teams')]
    #[ORM\JoinTable(name: 'ddrb_team_member')]
    #[ORM\JoinColumn(name: 'team_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'member_id', referencedColumnName: 'id')]
    public Collection $members;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string', length: 50)]
        public string $label = '',
    ) {
        $this->members = new ArrayCollection();
    }
}
