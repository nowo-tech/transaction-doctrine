<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A parent with a readonly id, a version and an inverse, cascade persist / refresh, orphanRemoval collection of leaves
 * (audits VQ / VQ2: probes P5, P7, P8, P11, P12).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_folder')]
class Folder
{
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    public int $version = 1;

    /** @var Collection<int, Leaf> */
    #[ORM\OneToMany(targetEntity: Leaf::class, mappedBy: 'folder', cascade: ['persist', 'refresh'], orphanRemoval: true)]
    public Collection $leaves;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public readonly int $id,
        #[ORM\Column(type: 'string', length: 20)]
        public string $label = '',
    ) {
        $this->leaves = new ArrayCollection();
    }
}
