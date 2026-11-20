<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\PrivateManager\Fixtures;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryInterface;
use Doctrine\ORM\EntityRepository;

if (!interface_exists(ServiceEntityRepositoryInterface::class)) {
    require __DIR__ . '/ServiceEntityRepositoryInterface.php';
}

/**
 * A container repository (DoctrineBundle's marker interface), resolved by the source factory.
 *
 * @extends EntityRepository<object>
 */
final class ServiceRepository extends EntityRepository implements ServiceEntityRepositoryInterface
{
}
