<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;

use function ltrim;
use function rtrim;
use function str_starts_with;
use function strrpos;
use function substr;

/**
 * The Doctrine registry a bundle's services see (1.9): the bundle's manager name and the bundle's entity classes
 * resolve to its PrivateEntityManager, everything else to the application's registry unchanged. Resetting the bundle's
 * manager drops the private one; the application's managers are never reset on the bundle's behalf.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class PrivateManagerRegistry implements ManagerRegistry
{
    /** @var list<string> */
    private array $prefixes;

    /**
     * @param ManagerRegistry $registry The application's registry
     * @param PrivateEntityManager $entityManager The bundle's private manager
     * @param list<string> $entityNamespaces The bundle's entity namespaces
     * @param string $managerName The source manager's name (the bundle's `entity_manager` setting, default `default`)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private PrivateEntityManager $entityManager,
        array $entityNamespaces,
        private string $managerName = 'default',
    ) {
        $prefixes = [];
        foreach ($entityNamespaces as $namespace) {
            $prefixes[] = rtrim(ltrim($namespace, '\\'), '\\') . '\\';
        }

        $this->prefixes = $prefixes;
    }

    public function getDefaultConnectionName(): string
    {
        return $this->registry->getDefaultConnectionName();
    }

    public function getConnection(?string $name = null): object
    {
        return $this->registry->getConnection($name);
    }

    public function getConnections(): array
    {
        return $this->registry->getConnections();
    }

    public function getConnectionNames(): array
    {
        return $this->registry->getConnectionNames();
    }

    public function getDefaultManagerName(): string
    {
        return $this->registry->getDefaultManagerName();
    }

    /** The private manager for the bundle's manager name, otherwise the application's manager. */
    public function getManager(?string $name = null): ObjectManager
    {
        return $this->isBundleManager($name) ? $this->entityManager : $this->registry->getManager($name);
    }

    /** The application's managers (the private one is not a named manager of the application). */
    public function getManagers(): array
    {
        return $this->registry->getManagers();
    }

    /** The bundle's manager name drops the private manager; any other name resets that application manager. */
    public function resetManager(?string $name = null): ObjectManager
    {
        if ($this->isBundleManager($name)) {
            $this->entityManager->reset();

            return $this->entityManager;
        }

        return $this->registry->resetManager($name);
    }

    public function getManagerNames(): array
    {
        return $this->registry->getManagerNames();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $persistentObject
     *
     * @return ObjectRepository<T>
     */
    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        if (($persistentManagerName === null || $this->isBundleManager($persistentManagerName)) && $this->isBundleEntity($persistentObject)) {
            return $this->entityManager->getRepository($persistentObject); // @phpstan-ignore return.type (the repository of that class)
        }

        return $this->registry->getRepository($persistentObject, $persistentManagerName);
    }

    /** The private manager for a bundle entity class, otherwise the application's answer. */
    public function getManagerForClass(string $class): ?ObjectManager
    {
        return $this->isBundleEntity($class) ? $this->entityManager : $this->registry->getManagerForClass($class);
    }

    /** Whether a class (or a Doctrine proxy class of it) is in one of the bundle's entity namespaces. */
    public function isBundleEntity(string $class): bool
    {
        $real = self::realClass($class);
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($real, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** The entity class of a Doctrine proxy class name (`…\__CG__\App\X` → `App\X`), or the class itself. */
    public static function realClass(string $class): string
    {
        $marker = strrpos($class, '\\__CG__\\');

        return $marker !== false ? substr($class, $marker + 8) : $class;
    }

    private function isBundleManager(?string $name): bool
    {
        return ($name ?? $this->registry->getDefaultManagerName()) === $this->managerName;
    }
}
