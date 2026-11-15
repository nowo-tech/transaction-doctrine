<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Database;

use Composer\InstalledVersions;
use Nowo\TransactionDoctrine\ConnectionRepairer;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\Tests\Database\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function version_compare;

/**
 * QA-TD11-L1: a nested leftover between requests. With DBAL 3's emulated nesting the inner rollback only marks the
 * transaction rollback-only; the repair rolls back level by level (clearing the flag) instead of closing, so the next
 * run commits. With savepoints (DBAL 4, or DBAL 3 with savepoints on) the leftover is closed, and the next run commits
 * too.
 */
final class ConnectionRepairerDatabaseTest extends DatabaseTestCase
{
    #[DataProvider('allServers')]
    public function testTheRunAfterARepairedNestedLeftoverCommits(string $server): void
    {
        $this->boot($server);
        $emulated = version_compare((string) InstalledVersions::getVersion('doctrine/dbal'), '4.0.0', '<')
            && !$this->connection->getNestTransactionsWithSavepoints();

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->connection->rollBack();   // inner rollback: rollback-only with emulated nesting
        self::assertSame(2, $this->connection->getTransactionNestingLevel());

        $action = (new ConnectionRepairer())->betweenRequests($this->connection);

        self::assertSame($emulated ? ConnectionRepairer::ACTION_ROLLED_BACK : ConnectionRepairer::ACTION_CLOSED, $action);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());

        DoctrineTransactionRunner::forConnection($this->connection)->run(function (): void {
            $this->connection->insert('ddrb_counter', ['id' => 5, 'counter_value' => 5]);
        });
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM ddrb_counter WHERE id = 5'));
    }
}
