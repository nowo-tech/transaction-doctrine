<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Database\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use PHPUnit\Framework\TestCase;

use function dirname;
use function getenv;
use function is_string;
use function str_starts_with;
use function usleep;

use const PHP_VERSION_ID;

/**
 * Base class of the real-database tests. They run only when the DSN of a server is set: TD_MYSQL_URL, TD_MARIADB_URL
 * (mysql://…) and TD_POSTGRES_URL (pgsql://…).
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    protected TestManagerRegistry $registry;

    protected ?SecondConnection $other = null;

    protected string $url = '';

    /**
     * @return iterable<string, array{string}>
     */
    public static function mysqlFamily(): iterable
    {
        yield 'MySQL 8.4' => ['TD_MYSQL_URL'];
        yield 'MariaDB 11.4' => ['TD_MARIADB_URL'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allServers(): iterable
    {
        yield from self::mysqlFamily();
        yield 'PostgreSQL 16' => ['TD_POSTGRES_URL'];
    }

    protected function boot(string $environmentVariable): void
    {
        $url = getenv($environmentVariable);

        if (!is_string($url) || $url === '') {
            self::markTestSkipped($environmentVariable . ' is not set.');
        }

        $this->url        = $url;
        $this->connection = $this->connect();
        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_counter');
        $this->connection->executeStatement('DROP TABLE IF EXISTS ddrb_ballast');
        $this->connection->executeStatement('CREATE TABLE ddrb_counter (id INT NOT NULL PRIMARY KEY, counter_value INT NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE ddrb_ballast (id INT NOT NULL PRIMARY KEY)');
        $this->connection->executeStatement('INSERT INTO ddrb_counter (id, counter_value) VALUES (1, 0), (2, 0)');

        $this->registry = new TestManagerRegistry($this->connection, static fn (Connection $connection): EntityManagerInterface => self::entityManager($connection));
    }

    protected function tearDown(): void
    {
        $this->other?->close();
        $this->other = null;

        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    protected static function entityManager(Connection $connection): EntityManagerInterface
    {
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([__DIR__]));
        $configuration->setProxyDir(dirname(__DIR__, 3) . '/var/cache/proxies');
        $configuration->setProxyNamespace('TdTestProxies');
        $configuration->setAutoGenerateProxyClasses(true);

        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }

        return new EntityManager($connection, $configuration);
    }

    protected function manager(): EntityManagerInterface
    {
        $manager = $this->registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    protected function isPostgreSql(): bool
    {
        return str_starts_with($this->url, 'pgsql');
    }

    protected function otherSession(): SecondConnection
    {
        return $this->other ??= new SecondConnection($this->url);
    }

    protected function freshValue(string $sql): mixed
    {
        $fresh = $this->connect();
        $value = $fresh->fetchOne($sql);
        $fresh->close();

        return $value;
    }

    /**
     * Makes the second session hold row 2 (heavier: the test's connection is the deadlock victim) and wait for row 1.
     */
    protected function startDeadlockingPeer(): void
    {
        $other = $this->otherSession();
        self::assertSame('OK', $other->execute('BEGIN'));
        self::assertSame('OK', $other->execute('UPDATE ddrb_counter SET counter_value = counter_value + 100 WHERE id = 2'));

        for ($i = 1; $i <= 20; ++$i) {
            self::assertSame('OK', $other->execute('INSERT INTO ddrb_ballast (id) VALUES (' . $i . ')'));
        }

        $other->send('UPDATE ddrb_counter SET counter_value = counter_value + 100 WHERE id = 1');
        usleep(300_000);
    }

    private function connect(): Connection
    {
        return DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']))->parse($this->url));
    }
}
