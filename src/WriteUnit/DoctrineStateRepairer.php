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
use function in_array;

/**
 * Puts every Doctrine manager and connection of a registry back in a usable state, so nothing of a failed unit of work
 * reaches the next attempt, request or message:
 *
 * - resetClosedManagers(): a closed EntityManager is reset through the registry (no query);
 * - discardUnitOfWork(): between two attempts of a write unit, the transaction levels a unit left open are rolled back
 *   (ConnectionRepairer: closed only when the rollback fails) on every connection, closed managers reset and open
 *   ones cleared; every manager by default, or only the named ones (1.7: a write unit scoped to a bundle's own manager
 *   leaves the host's default manager alone). An open manager given entities to keep (1.8, audit VD2-4: the entities
 *   it managed before the unit started) is never cleared: it is put back to what the database holds
 *   (KeptEntitiesRestorer);
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
     * Between two attempts of a write unit: leftover levels rolled back on every connection, closed managers reset,
     * open ones cleared.
     *
     * Call it only outside a caller's transaction (audit UI2-2): it rolls back every open level on every connection,
     * so called while the host or an outer unit has a transaction open, it rolls that transaction back.
     *
     * @param list<string>|null $managers Null (the default): every manager of the registry, the host's default one
     *                                    included. A list of manager names (transaction-doctrine >= 1.7, audit UD2-L-2):
     *                                    only those managers are reset or cleared; every other manager keeps its
     *                                    entities. Leftover transaction levels are rolled back on EVERY connection
     *                                    either way (audit UI-1: a write unit only starts outside any transaction, so
     *                                    an open level is always the unit's, or a retry listener's). When a manager
     *                                    outside the list is found closed (the unit wrote through a manager it did not
     *                                    name), every manager is repaired, with a warning (audit UI-3)
     * @param array<string, AttemptObserver> $kept For WriteUnit (internal type), per manager name: the entities the
     *                                             manager managed before the unit's first attempt and what the failed
     *                                             attempt did to it (1.8, audits VD2-4 / VQ). Such an open manager is
     *                                             never cleared: KeptEntitiesRestorer puts it back to what the database
     *                                             holds. Empty (the default): open managers are cleared, as before 1.8
     * @param bool $everything Reload every initialised kept entity (an abandoned replay)
     *
     * @return list<string> The managers whose kept entities could not all be restored (detached by the attempt, a row
     *                      gone, a reload that failed); they are left open, restored as far as possible, never cleared
     */
    public function discardUnitOfWork(?array $managers = null, array $kept = [], bool $everything = false): array
    {
        foreach ($this->connections() as $connection) {
            $this->connectionRepairer->afterFailure($connection, 0);
        }

        if ($managers !== null && $this->closedOutside($managers)) {
            SafeLog::log($this->logger, 'warning', 'A manager outside the write unit\'s scope was closed; repaired every manager.', []);
            $managers = null;
        }

        $unrestored = [];

        foreach ($managers ?? array_keys($this->registry->getManagerNames()) as $name) {
            $manager = $this->registry->getManager($name);
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->registry->resetManager($name);

                continue;
            }

            if (isset($kept[$name])) {
                if (!KeptEntitiesRestorer::restore($kept[$name], $everything)) {
                    SafeLog::log($this->logger, 'warning', 'Entities managed before the write unit could not all be restored; the manager is left open and not cleared.', [
                        'manager' => $name,
                    ]);
                    $unrestored[] = $name;
                }

                continue;
            }

            $manager->clear(); // @igor-ignore — the unit of work the failed attempt left; nothing is kept on the service
        }

        return $unrestored;
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
     * Whether an entity manager not named in $managers is closed.
     *
     * @param list<string> $managers
     */
    private function closedOutside(array $managers): bool
    {
        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            if (in_array($name, $managers, true)) {
                continue;
            }

            $manager = $this->registry->getManager($name);
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                return true;
            }
        }

        return false;
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
