<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host entity with backed-enum fields (audit XA-1): a plain one, a nullable one and an array of cases
 * (simple_array). The loaded state holds the cases, the field values their backing values.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'ddrb_ticket')]
class Ticket
{
    /**
     * @param list<Kind> $kinds
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string', length: 10, enumType: Kind::class)]
        public Kind $status = Kind::Alpha,
        #[ORM\Column(type: 'string', length: 10, nullable: true, enumType: Kind::class)]
        public ?Kind $priority = null,
        #[ORM\Column(type: 'simple_array', length: 100, enumType: Kind::class)]
        public array $kinds = [],
    ) {
    }
}
