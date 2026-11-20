<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * A child of a Folder (owning side, cascade refresh towards the folder).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_leaf')]
class Leaf
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\ManyToOne(targetEntity: Folder::class, cascade: ['refresh'], inversedBy: 'leaves')]
        #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', nullable: false)]
        public Folder $folder,
        #[ORM\Column(type: 'string', length: 20)]
        public string $label = '',
    ) {
    }
}
