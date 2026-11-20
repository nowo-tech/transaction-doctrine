<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * An SQL filter with a parameter (1.9 private manager tests: the private manager mirrors the source's filters).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
class TenantFilter extends SQLFilter
{
    /**
     * @param ClassMetadata<object> $targetEntity
     * @param string $targetTableAlias Untyped: ORM 2 declares no parameter type
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string // phpcs:ignore
    {
        return $targetEntity->getName() === BundleNote::class && $this->hasParameter('counter')
            ? $targetTableAlias . '.counter_id = ' . $this->getParameter('counter')
            : '';
    }
}
