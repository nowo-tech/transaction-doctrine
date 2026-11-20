<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The owning side of a many-to-many (audit WD-4: a clear() of it deletes the join rows). Maps ddrb_board's rows.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_board')]
class Shelf
{
    /** @var Collection<int, Counter> */
    #[ORM\ManyToMany(targetEntity: Counter::class)]
    #[ORM\JoinTable(name: 'ddrb_shelf_counter')]
    #[ORM\JoinColumn(name: 'shelf_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'counter_id', referencedColumnName: 'id')]
    public Collection $counters;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
    ) {
        $this->counters = new ArrayCollection();
    }
}
