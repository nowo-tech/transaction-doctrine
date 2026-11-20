<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Compiler pass of a bundle's private entity manager (1.9): checks that the source manager the bundle names exists,
 * and tags the bundle's PrivateEntityManagerMiddleware service `doctrine.middleware` for that manager's connection
 * only (zero-config: the default manager on the default connection), on DBAL 3 and 4.
 *
 * A bundle adds it once per private manager, for example:
 * `$container->addCompilerPass(new PrivateEntityManagerPass('my_bundle.private_em_middleware', 'my_bundle.entity_manager', 'my_bundle.doctrine.entity_manager'));`
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class PrivateEntityManagerPass implements CompilerPassInterface
{
    /**
     * @param string $middlewareId The bundle's PrivateEntityManagerMiddleware service id
     * @param string|null $managerParameter The container parameter holding the source manager's name (missing or
     *                                      empty: `default`)
     * @param string $settingName The bundle setting named in errors (for example `my_bundle.doctrine.entity_manager`)
     */
    public function __construct(
        private string $middlewareId,
        private ?string $managerParameter = null,
        private string $settingName = 'entity_manager',
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $manager = $this->managerParameter !== null && $container->hasParameter($this->managerParameter) ? $container->getParameter($this->managerParameter) : null;
        $manager = is_string($manager) && $manager !== '' ? $manager : 'default';

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : null;
        if (is_array($managers) && $managers !== [] && !isset($managers[$manager])) {
            throw new InvalidConfigurationException(sprintf('%s is "%s", but no such Doctrine entity manager is configured (known: "%s").', $this->settingName, $manager, implode('", "', array_keys($managers))));
        }

        if (!$container->hasDefinition($this->middlewareId)) {
            return;
        }

        $id         = sprintf('doctrine.orm.%s_entity_manager', $manager);
        $argument   = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        $connection = $argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1 ? $match[1] : null;
        if ($connection === null) {
            $default    = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connection = is_string($default) && $default !== '' ? $default : 'default';
        }

        $container->getDefinition($this->middlewareId)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection]);
    }
}
