<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A parent whose cards cascade persist with orphan removal (audit TA3-2: a rolled-back child left in its collection).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_board')]
class Board
{
    /** @var Collection<int, Card> */
    #[ORM\OneToMany(targetEntity: Card::class, mappedBy: 'board', cascade: ['persist'], orphanRemoval: true)]
    public Collection $cards;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
    ) {
        $this->cards = new ArrayCollection();
    }
}
