<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * A versioned row (optimistic locking): a write-unit replay must start from the stored version (audit VQ-1).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_doc')]
class Doc
{
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    public int $version = 1;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string', length: 20)]
        public string $label = '',
    ) {
    }
}
