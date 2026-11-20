<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * A read-only entity (audit WD-6: Doctrine never writes its changes). Maps ddrb_owner's rows.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'ddrb_owner')]
class Frozen
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string', length: 50)]
        public string $label = '',
    ) {
    }
}
