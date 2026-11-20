<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host;

use Doctrine\ORM\Mapping as ORM;

/**
 * A "bundle" entity in its own namespace (1.9 host-flush probe and private manager tests): the classes of the parent
 * namespace play the host's.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_note')]
class BundleNote
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(name: 'counter_id', type: 'integer')]
        public int $counterId,
    ) {
    }
}
