<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * A row keyed by a backed enum (audit VQ6-4, probe Q2P).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_enum')]
class EnumKeyed
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 10, enumType: Kind::class)]
        public Kind $kind,
        #[ORM\Column(type: 'string', length: 50)]
        public string $label = '',
    ) {
    }
}
