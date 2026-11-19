<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Proxy;

/**
 * ManagerRegistry of a host (`default`) and a bundle's private (`private`) entity manager, each on its own connection,
 * as a bundle with its own manager sees them; resetManager() builds a new manager on the same connection.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TwoManagerRegistry extends AbstractManagerRegistry
{
    /** @var array<string, EntityManagerInterface> */
    private array $managers;

    /** @var array<string, Connection> */
    private readonly array $connections;

    /**
     * @param Closure(Connection): EntityManagerInterface $managerFactory
     */
    public function __construct(Connection $host, Connection $private, private readonly Closure $managerFactory)
    {
        parent::__construct(
            'two',
            ['default' => 'connection.default', 'private' => 'connection.private'],
            ['default' => 'manager.default', 'private' => 'manager.private'],
            'default',
            'default',
            Proxy::class,
        );
        $this->connections = ['default' => $host, 'private' => $private];
        $this->managers    = ['default' => ($managerFactory)($host), 'private' => ($managerFactory)($private)];
    }

    public function entityManager(string $name): EntityManagerInterface
    {
        return $this->managers[$name];
    }

    protected function getService(string $name): object
    {
        [$kind, $key] = explode('.', $name, 2);

        return $kind === 'connection' ? $this->connections[$key] : $this->managers[$key];
    }

    protected function resetService(string $name): void
    {
        [$kind, $key] = explode('.', $name, 2);
        if ($kind === 'manager') {
            $this->managers[$key] = ($this->managerFactory)($this->connections[$key]);
        }
    }
}
