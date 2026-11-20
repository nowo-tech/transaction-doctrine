<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\ObjectRepository;

use function is_a;

/**
 * Repository factory of ONE private entity manager (1.9): the shared factory of the source configuration (DoctrineBundle's
 * ContainerRepositoryFactory) keeps one repository per manager instance for the life of the process, so every replaced
 * private manager would stay in memory in worker mode. This factory belongs to one manager and is collected with it.
 *
 * - A service repository (DoctrineBundle's `ServiceEntityRepositoryInterface`) is still resolved by the source factory:
 *   it is a container service, not keyed by manager, and does not leak.
 * - Any other repository is built here, with the mapped `repositoryClass` or the configuration's default one.
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PrivateManagerRepositoryFactory implements RepositoryFactory
{
    /** DoctrineBundle's marker interface of container repositories (no hard dependency). */
    public const SERVICE_REPOSITORY = ServiceEntityRepositoryInterface::class;

    /** @var array<string, ObjectRepository<object>> */
    private array $repositories = [];

    public function __construct(
        private readonly RepositoryFactory $source,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityName Untyped: ORM 2's interface declares no parameter type
     *
     * @return EntityRepository<T>
     */
    public function getRepository(EntityManagerInterface $entityManager, $entityName): EntityRepository // phpcs:ignore
    {
        $metadata = $entityManager->getClassMetadata($entityName);
        $class    = $metadata->customRepositoryClassName;
        if ($class !== null && is_a($class, self::SERVICE_REPOSITORY, true)) {
            /** @var EntityRepository<T> $service */
            $service = $this->source->getRepository($entityManager, $entityName);

            return $service;
        }

        /** @var EntityRepository<T> $repository */
        $repository = $this->repositories[$metadata->getName()] ??= $this->create($entityManager, $metadata, $class ?? $entityManager->getConfiguration()->getDefaultRepositoryClassName()); // @igor-ignore — per-manager cache, collected with its manager

        return $repository;
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param class-string $class
     *
     * @return ObjectRepository<object>
     */
    private function create(EntityManagerInterface $entityManager, ClassMetadata $metadata, string $class): ObjectRepository
    {
        /** @var ObjectRepository<object> $repository */
        $repository = new $class($entityManager, $metadata);

        return $repository;
    }
}
