<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\Mapping\ClassMetadata;
use RuntimeException;

/**
 * A manager whose class metadata cannot be resolved, as with a broken proxy (audit XA-3: the host-flush probe's
 * inspection throws, the probe does not).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class BrokenMetadataManager extends EntityManagerDecorator
{
    /**
     * Untyped parameter: ORM 2's signature.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return ClassMetadata<T>
     */
    public function getClassMetadata($className): ClassMetadata
    {
        throw new RuntimeException('Broken proxy: ' . $className);
    }
}
