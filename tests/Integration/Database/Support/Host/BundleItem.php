<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\HostItem;

/**
 * A "bundle" single-table root the host extends (audit WD-6: each entity's own class decides, not the root's).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_item')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 10)]
#[ORM\DiscriminatorMap(['bundle' => BundleItem::class, 'host' => HostItem::class])]
class BundleItem
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string', length: 20)]
        public string $label = '',
    ) {
    }
}
