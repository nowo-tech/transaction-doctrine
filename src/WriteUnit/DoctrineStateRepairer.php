<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\ConnectionRepairer;
use Nowo\TransactionDoctrine\SafeLog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_keys;
use function array_values;
use function spl_object_id;

/**
 * Puts every Doctrine manager and connection of a registry back in a usable state, so nothing of a failed unit of work
 * reaches the next attempt, request or message:
 *
 * - resetClosedManagers(): a closed EntityManager is reset through the registry (no query);
 * - discardUnitOfWork(): between two attempts of a write unit, the transaction levels a unit left open are rolled back
 *   (ConnectionRepairer: closed only when the rollback fails), closed managers reset and open ones cleared; every
 *   manager by default, or only the named ones and their connections (1.7: a write unit scoped to a bundle's own
 *   manager leaves the host's default manager alone);
 * - afterDatabaseFailure(): after a DBAL / ORM failure of a request or message, closed managers reset, leftover levels
 *   rolled back, and every connected connection pinged (closed when it no longer answers; DBAL reconnects on the next
 *   query). A connection that is not connected is never opened.
 *
 * Stateless (FrankenPHP worker, kernel reset off): every call reads the registry. Logs constant messages only.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class DoctrineStateRepairer
{
    private LoggerInterface $logger;

    private ConnectionRepairer $connectionRepairer;

    public function __construct(
        private ManagerRegistry $registry,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger             = $logger ?? new NullLogger();
        $this->connectionRepairer = new ConnectionRepairer(null, $this->logger);
    }

    /**
     * @return int How many closed managers were reset
     */
    public function resetClosedManagers(): int
    {
        $reset = 0;

        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            $manager = $this->registry->getManager($name);
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->registry->resetManager($name);
                ++$reset;
            }
        }

        if ($reset > 0) {
            SafeLog::log($this->logger, 'notice', 'Reset closed entity managers.', ['managers' => $reset]);
        }

        return $reset;
    }

    /**
     * Between two attempts of a write unit: leftover levels rolled back, closed managers reset, open ones cleared.
     *
     * @param list<string>|null $managers Null (the default): every manager and connection of the registry, the host's
     *                                    default manager included. A list of manager names (transaction-doctrine >= 1.7,
     *                                    audit UD2-L-2): only those managers, and only their connections, are repaired;
     *                                    every other manager keeps its entities
     */
    public function discardUnitOfWork(?array $managers = null): void
    {
        $connections = $managers === null ? $this->connections() : $this->connectionsOf($managers);
        foreach ($connections as $connection) {
            $this->connectionRepairer->afterFailure($connection, 0);
        }

        foreach ($managers ?? array_keys($this->registry->getManagerNames()) as $name) {
            $manager = $this->registry->getManager($name);
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->registry->resetManager($name);

                continue;
            }

            $manager->clear(); // @igor-ignore — the unit of work the failed attempt left; nothing is kept on the service
        }
    }

    /** After a DBAL / ORM failure: closed managers reset, leftover levels rolled back, connected connections pinged. */
    public function afterDatabaseFailure(): void
    {
        $this->resetClosedManagers();

        foreach ($this->connections() as $connection) {
            $this->connectionRepairer->afterFailure($connection, 0);
            $this->ping($connection);
        }
    }

    /**
     * @return list<Connection>
     */
    private function connections(): array
    {
        $connections = [];

        foreach ($this->registry->getConnections() as $connection) {
            if ($connection instanceof Connection) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    /**
     * The connections of the named entity managers, each once.
     *
     * @param list<string> $managers
     *
     * @return list<Connection>
     */
    private function connectionsOf(array $managers): array
    {
        $connections = [];

        foreach ($managers as $name) {
            $manager = $this->registry->getManager($name);
            if ($manager instanceof EntityManagerInterface) {
                $connection                              = $manager->getConnection();
                $connections[spl_object_id($connection)] = $connection;
            }
        }

        return array_values($connections);
    }

    private function ping(Connection $connection): void
    {
        if (!$connection->isConnected()) {
            return;
        }

        try {
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (Throwable $failure) {
            SafeLog::log($this->logger, 'warning', 'Database connection lost after a failure: closed, the next query reconnects.', SafeLog::throwable($failure));
            $connection->close();
        }
    }
}
