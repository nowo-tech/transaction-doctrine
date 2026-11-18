<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * A child of a Board (audit TA3-2).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_card')]
class Card
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\ManyToOne(targetEntity: Board::class, inversedBy: 'cards')]
        #[ORM\JoinColumn(name: 'board_id', referencedColumnName: 'id', nullable: false)]
        public Board $board,
    ) {
    }
}
