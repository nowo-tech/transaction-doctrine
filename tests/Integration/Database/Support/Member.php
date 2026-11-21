<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The inverse side of Team's many-to-many (1.9.3): a change of it alone writes nothing.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_member')]
class Member
{
    /** @var Collection<int, Team> */
    #[ORM\ManyToMany(targetEntity: Team::class, mappedBy: 'members')]
    public Collection $teams;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string', length: 50)]
        public string $label = '',
    ) {
        $this->teams = new ArrayCollection();
    }
}
