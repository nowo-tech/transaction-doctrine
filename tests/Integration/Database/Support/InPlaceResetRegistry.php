<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Proxy;

/**
 * A ManagerRegistry whose resetManager() resets the manager in place (1.8.1): getManager() keeps returning the same
 * object, which serves a new entity manager afterwards, as DoctrineBundle's lazy entity manager service does.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class InPlaceResetRegistry extends AbstractManagerRegistry
{
    public readonly InPlaceManager $manager;

    public int $resets = 0;

    /**
     * @param Closure(Connection): EntityManagerInterface $managerFactory
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Closure $managerFactory,
    ) {
        parent::__construct('test', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default', Proxy::class);
        $this->manager = new InPlaceManager(($this->managerFactory)($this->connection));
    }

    protected function getService(string $name): object
    {
        return $name === 'connection' ? $this->connection : $this->manager;
    }

    protected function resetService(string $name): void
    {
        if ($name === 'manager') {
            ++$this->resets;
            $this->manager->swap(($this->managerFactory)($this->connection));
        }
    }
}
