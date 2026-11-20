<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entity with an object field (audit WD-6: an equal object replacing the loaded one is written only by ORMs that
 * compare object fields by identity).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_stamp')]
class Stamp
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(name: 'stamped_at', type: 'datetime_immutable')]
        public DateTimeImmutable $at,
    ) {
    }
}
