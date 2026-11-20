<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\PrivateManager;

use Closure;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\TransactionDoctrine\PrivateManager\DependencyInjection\PrivateEntityManagerPass;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerConnection;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRepositoryFactory;
use Nowo\TransactionDoctrine\PrivateManager\TransactionLevelListenerInterface;
use Nowo\TransactionDoctrine\Tests\Unit\PrivateManager\Fixtures\ServiceRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The 1.9 private-manager kit pieces that need no database: the compiler pass, the registry's delegation, the
 * repository factory's service-repository path, the connection's refused commit, ORM 2 filter parameters.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PrivateManagerKitTest extends TestCase
{
    public function testThePassTagsTheMiddlewareForTheSourceManagersConnection(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager', 'audit' => 'doctrine.orm.audit_entity_manager']);
        $container->setParameter('my.manager', 'audit');
        $container->setDefinition('doctrine.orm.audit_entity_manager', new Definition(stdClass::class, [new Reference('doctrine.dbal.logs_connection')]));
        $container->setDefinition('my.middleware', (new Definition(stdClass::class))->addTag('doctrine.middleware'));

        (new PrivateEntityManagerPass('my.middleware', 'my.manager', 'my.entity_manager'))->process($container);

        if (!PrivateEntityManagerMiddleware::supported()) {
            self::assertFalse($container->hasDefinition('my.middleware'), 'DBAL 3: removed');

            return;
        }

        self::assertSame([['connection' => 'logs']], $container->getDefinition('my.middleware')->getTag('doctrine.middleware'));
    }

    public function testThePassFallsBackToTheDefaultConnection(): void
    {
        foreach ([null, 'conn'] as $default) {
            $container = new ContainerBuilder();
            if ($default !== null) {
                $container->setParameter('doctrine.default_connection', $default);
            }

            $container->setDefinition('my.middleware', new Definition(stdClass::class));
            (new PrivateEntityManagerPass('my.middleware'))->process($container);
            if (PrivateEntityManagerMiddleware::supported()) {
                self::assertSame([['connection' => $default ?? 'default']], $container->getDefinition('my.middleware')->getTag('doctrine.middleware'));
            }
        }

        // No middleware definition (the bundle did not register one): nothing to do.
        $container = new ContainerBuilder();
        (new PrivateEntityManagerPass('missing'))->process($container);
        self::assertFalse($container->hasDefinition('missing'));
    }

    public function testThePassRemovesTheMiddlewareWhenRollbacksCannotBeHeard(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('my.middleware', new Definition(stdClass::class));
        (new PrivateEntityManagerPass('my.middleware', null, 'entity_manager', false))->process($container);
        self::assertFalse($container->hasDefinition('my.middleware'));
    }

    public function testThePassRefusesAnUnknownManager(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager']);
        $container->setParameter('my.manager', 'nope');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('my.entity_manager is "nope"');
        (new PrivateEntityManagerPass('my.middleware', 'my.manager', 'my.entity_manager'))->process($container);
    }

    public function testTheMiddlewareSupportMatchesTheDriverConnection(): void
    {
        self::assertSame((string) (new ReflectionMethod(Connection::class, 'rollBack'))->getReturnType() === 'void', PrivateEntityManagerMiddleware::supported());
    }

    public function testARefusedCommitCountsAsARollback(): void
    {
        if (!PrivateEntityManagerMiddleware::supported()) {
            self::assertFalse(PrivateEntityManagerMiddleware::supported());

            return;
        }

        $driver = $this->createMock(Connection::class);
        $driver->method('commit')->willThrowException(new RuntimeException('refused'));
        $listener = $this->createMock(TransactionLevelListenerInterface::class);
        $listener->expects($this->once())->method('connectionRolledBack')->with(1);
        $listener->expects($this->never())->method('connectionCommitted');

        $this->expectException(RuntimeException::class);
        (new PrivateEntityManagerConnection($driver, $listener))->commit();
    }

    public function testTheRegistryDelegatesEverythingElse(): void
    {
        $app = $this->createMock(ManagerRegistry::class);
        $app->method('getDefaultConnectionName')->willReturn('default');
        $app->method('getConnection')->willReturn($connection = new stdClass());
        $app->method('getConnections')->willReturn(['default' => $connection]);
        $app->method('getConnectionNames')->willReturn(['default' => 'doctrine.dbal.default_connection']);
        $app->method('getDefaultManagerName')->willReturn('default');
        $app->method('getManagerNames')->willReturn(['default' => 'm', 'other' => 'o']);
        $other = $this->createMock(ObjectManager::class);
        $app->method('getManager')->with('other')->willReturn($other);
        $app->expects($this->once())->method('resetManager')->with('other')->willReturn($other);
        $repository = $this->createMock(EntityRepository::class);
        $app->method('getRepository')->with(stdClass::class, 'other')->willReturn($repository);

        $registry = new PrivateManagerRegistry($app, new PrivateEntityManager($app), ['App\\Bundle\\Entity'], 'default');
        self::assertSame('default', $registry->getDefaultConnectionName());
        self::assertSame($connection, $registry->getConnection());
        self::assertSame(['default' => $connection], $registry->getConnections());
        self::assertSame(['default' => 'doctrine.dbal.default_connection'], $registry->getConnectionNames());
        self::assertSame('default', $registry->getDefaultManagerName());
        self::assertSame(['default' => 'm', 'other' => 'o'], $registry->getManagerNames());
        self::assertSame($other, $registry->getManager('other'));
        self::assertSame($other, $registry->resetManager('other'));
        self::assertSame($repository, $registry->getRepository(stdClass::class, 'other'));
        self::assertSame(stdClass::class, PrivateManagerRegistry::realClass(stdClass::class));
    }

    public function testServiceRepositoriesComeFromTheSourceFactory(): void
    {
        $metadata                            = new ClassMetadata(stdClass::class);
        $metadata->customRepositoryClassName = ServiceRepository::class;
        $manager                             = $this->createMock(EntityManagerInterface::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $service = $this->createMock(EntityRepository::class);
        $source  = $this->createMock(RepositoryFactory::class);
        $source->expects($this->once())->method('getRepository')->with($manager, stdClass::class)->willReturn($service);

        self::assertSame($service, (new PrivateManagerRepositoryFactory($source))->getRepository($manager, stdClass::class));
    }

    /** ORM 2 keeps filter parameters as arrays (value, type, is_list): read as such. */
    public function testOrm2FilterParametersAreRead(): void
    {
        $filter = new class($this->createMock(EntityManagerInterface::class)) extends SQLFilter {
            public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string // @phpstan-ignore missingType.parameter (ORM 2 declares no type)
            {
                return '';
            }
        };
        Closure::bind(static function (SQLFilter $filter): void {
            $filter->parameters = ['one' => ['value' => 'x', 'type' => 'string', 'is_list' => false], 'list' => ['value' => [1], 'type' => null, 'is_list' => true]]; // @phpstan-ignore assign.propertyType (ORM 2's shape)
        }, null, SQLFilter::class)($filter);

        $read = (new ReflectionMethod(PrivateEntityManager::class, 'filterParameters'))->invoke(new PrivateEntityManager($this->createMock(ManagerRegistry::class)), $filter);
        self::assertSame(['one' => ['x', 'string', false], 'list' => [[1], null, true]], $read);
    }
}
