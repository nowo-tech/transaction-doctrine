<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * A row whose relation to a Counter cascades detach (QA-TD11-L3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_note')]
class Note
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\ManyToOne(targetEntity: Counter::class, cascade: ['detach'])]
        #[ORM\JoinColumn(name: 'counter_id', referencedColumnName: 'id')]
        public Counter $counter,
    ) {
    }
}
