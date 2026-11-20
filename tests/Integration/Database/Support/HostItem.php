<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host\BundleItem;

/**
 * The host's subclass of a bundle-owned single-table root (audit WD-6).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
class HostItem extends BundleItem
{
}
