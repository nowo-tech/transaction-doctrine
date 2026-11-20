<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Events;
use LogicException;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManager;
use Nowo\TransactionDoctrine\PrivateManager\PrivateEntityManagerMiddleware;
use Nowo\TransactionDoctrine\PrivateManager\PrivateManagerRegistry;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host\BundleNote;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host\OtherTenantFilter;
use Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host\TenantFilter;
use Nowo\TransactionDoctrine\Tests\Unit\RecordingLogger;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingDriver;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use stdClass;
use Stringable;

use function count;

/**
 * 1.9: the shared private entity manager kit on a real ORM. Shared by the real-database suite and the unit suite (SQLite
 * file).
 *
 * @phpstan-require-extends DatabaseTestCase
 */
trait PrivateManagerScenarios
{
    private function privateManager(?LoggerInterface $logger = null, ?TestManagerRegistry $registry = null): PrivateEntityManager
    {
        return new PrivateEntityManager($registry ?? $this->registry, 'default', BundleNote::class, 'TestBundle', $logger);
    }

    /**
     * The bundle writes through its private manager on the host's connection: the host's pending change is not
     * flushed, the host's manager is untouched, and a boundary drops the private manager (logging what it dropped).
     */
    private function scenarioThePrivateManagerNeverFlushesTheHostsWork(): void
    {
        $logger  = new RecordingLogger();
        $private = $this->privateManager($logger);
        $host    = $this->manager();
        self::assertFalse($private->isCreated());

        $counter = $host->find(Counter::class, 1);
        self::assertInstanceOf(Counter::class, $counter);
        $counter->value = 5; // the host's pending change

        $note = $private->find(BundleNote::class, 1);
        self::assertInstanceOf(BundleNote::class, $note);
        self::assertTrue($private->isCreated());
        self::assertNotSame($host, $private->live());
        self::assertSame($host->getConnection(), $private->getConnection());
        $note->counterId = 2;
        $private->flush();
        $host->flush(); // the host's own flush (its event reaches the shared event manager: ignored by the private one)

        self::assertSame(2, (int) $this->freshValue('SELECT counter_id FROM ddrb_note WHERE id = 1'));
        self::assertSame(5, (int) $this->freshValue('SELECT counter_value FROM ddrb_counter WHERE id = 1'));
        self::assertTrue($host->contains($counter));
        self::assertFalse($private->live()->contains($counter));

        $note->counterId = 3; // pending in the private manager at the boundary
        $private->reset();
        self::assertFalse($private->isCreated());
        self::assertSame(['warning'], $logger->levels());
        self::assertSame(['bundle' => 'TestBundle', 'count' => 1, 'changes' => [BundleNote::class => 1]], $logger->records[0]['context']);
        self::assertSame(2, (int) $this->freshValue('SELECT counter_id FROM ddrb_note WHERE id = 1'));
        $private->reset(); // nothing created: nothing to do

        $private->live(); // a clean manager dropped at the boundary logs nothing
        $private->reset();
        self::assertCount(1, $logger->records);
        $private->reset();
    }

    /** A closed private manager (its own failed flush) is replaced; the host's manager stays open. */
    private function scenarioAClosedPrivateManagerIsReplaced(): void
    {
        $private = $this->privateManager();
        $first   = $private->live();
        $first->close();
        $second = $private->live();
        self::assertNotSame($first, $second);
        self::assertTrue($second->isOpen());
        self::assertTrue($this->manager()->isOpen());
    }

    /** The source must be an ORM manager mapping the bundle's entities. */
    private function scenarioAWrongSourceIsRefused(): void
    {
        $private = new PrivateEntityManager($this->registry, 'default', stdClass::class);

        try {
            $private->live();
            self::fail('No LogicException.');
        } catch (LogicException $exception) {
            self::assertStringContainsString(stdClass::class, $exception->getMessage());
        }

        try {
            $private->__get('other');
            self::fail('No LogicException.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('$other', $exception->getMessage());
        }
    }

    /**
     * The source's SQL filters are mirrored: enabled with their parameters, re-created when a parameter changes, a name
     * registered again with another class, a filter registered after the private manager was created, disabled.
     */
    private function scenarioTheSourcesFiltersAreMirrored(): void
    {
        $host = $this->manager();
        $host->getConfiguration()->addFilter('tenant', TenantFilter::class);
        $private = $this->privateManager();
        $notes   = static fn (): int => count($private->getRepository(BundleNote::class)->findAll());

        self::assertSame(1, $notes());
        $host->getFilters()->enable('tenant')->setParameter('counter', 1)->setParameterList('ids', [1, 2]);
        self::assertSame(1, $notes(), 'counter_id = 1');
        $mirrored = $private->live()->getFilters()->getFilter('tenant');
        self::assertSame((string) $host->getFilters()->getFilter('tenant'), (string) $mirrored);

        $host->getFilters()->getFilter('tenant')->setParameter('counter', 2);
        self::assertSame(0, $notes(), 'the changed parameter re-created the filter');

        $host->getFilters()->disable('tenant');
        $host->getConfiguration()->addFilter('tenant', OtherTenantFilter::class);
        $host->getFilters()->enable('tenant')->setParameter('counter', 1);
        self::assertSame(1, $notes());
        self::assertInstanceOf(OtherTenantFilter::class, $private->live()->getFilters()->getFilter('tenant'));

        $host->getConfiguration()->addFilter('late', TenantFilter::class);
        $host->getFilters()->enable('late')->setParameter('counter', 3);
        self::assertSame(0, $notes(), 'a filter registered after the private manager was created');

        $host->getFilters()->disable('late');
        $host->getFilters()->disable('tenant');
        self::assertSame(1, $notes());
        self::assertSame([], $private->live()->getFilters()->getEnabledFilters());
    }

    /** The registry the bundle's services see. */
    private function scenarioTheRegistryResolvesTheBundlesManager(): void
    {
        $private  = $this->privateManager();
        $registry = new PrivateManagerRegistry($this->registry, $private, [self::bundleNamespace()]);

        self::assertSame($private, $registry->getManager());
        self::assertSame($private, $registry->getManager('default'));
        self::assertSame($private, $registry->getManagerForClass(BundleNote::class));
        self::assertSame($this->manager(), $registry->getManagerForClass(Counter::class));
        $bundleRepository = $registry->getRepository(BundleNote::class);
        $hostRepository   = $registry->getRepository(Counter::class);
        self::assertInstanceOf(EntityRepository::class, $bundleRepository);
        self::assertInstanceOf(EntityRepository::class, $hostRepository);
        self::assertSame($private->live(), $bundleRepository->createQueryBuilder('n')->getEntityManager());
        self::assertSame($this->manager(), $hostRepository->createQueryBuilder('c')->getEntityManager());
        self::assertSame($this->manager(), $registry->getManagers()['default']);
        self::assertTrue($registry->isBundleEntity('Proxies\\__CG__\\' . BundleNote::class));

        $private->live();
        self::assertSame($private, $registry->resetManager());
        self::assertFalse($private->isCreated());
    }

    /**
     * The middleware tells the private manager how the connection's transactions end (DBAL 3 and 4, audit WD-3). A
     * rollback of a level it wrote or loaded in makes its identity map stale (replaced at the next use, unless work is
     * still pending); a commit, a rollback of a deeper level, or of the bundle's own savepoint, does not.
     */
    private function scenarioARollbackMakesThePrivateManagerStale(): void
    {
        [$connection, $private] = $this->trackedPrivateManager();

        try {
            // A write inside a transaction that is rolled back: stale, replaced at the next use.
            $connection->beginTransaction();
            $first = $private->live();
            $note  = $private->find(BundleNote::class, 1);
            self::assertInstanceOf(BundleNote::class, $note);
            $note->counterId = 7;
            $private->flush();
            $connection->rollBack();
            self::assertTrue($private->isStale());
            self::assertNotSame($first, $private->live());
            self::assertFalse($private->isStale());

            // Committed: durable, never stale.
            $connection->beginTransaction();
            $private->noteWrite();
            $connection->commit();
            $connection->beginTransaction();
            $connection->rollBack();
            self::assertFalse($private->isStale());

            // A deeper level the manager did not write in, and the bundle's own savepoint: not stale.
            $connection->beginTransaction();
            $private->noteWrite();
            $connection->beginTransaction();
            $connection->rollBack();
            $connection->executeStatement('SAVEPOINT bundle_sp');
            $connection->executeStatement('ROLLBACK TO SAVEPOINT bundle_sp');
            self::assertFalse($private->isStale());

            // An application savepoint of unknown level undoes everything pending: stale.
            $connection->executeStatement('SAVEPOINT app_sp');
            $connection->executeStatement('ROLLBACK TO SAVEPOINT app_sp');
            self::assertTrue($private->isStale());
            $connection->rollBack();

            // A loaded row inside a transaction, then a released savepoint moving it down, then a rollback: stale; but a
            // stale manager with pending work is kept until that work is flushed.
            $private->reset();
            $connection->beginTransaction();
            $connection->beginTransaction();
            $kept = $private->live();
            $note = $private->find(BundleNote::class, 1);
            self::assertInstanceOf(BundleNote::class, $note);
            $connection->commit(); // releases level 2 into level 1
            $note->counterId = 8; // pending
            $connection->rollBack();
            self::assertTrue($private->isStale());
            self::assertSame($kept, $private->live(), 'pending work keeps the stale manager');
        } finally {
            $private->reset();
            $connection->close();
        }
    }

    /**
     * A private manager on its own connection, wrapped by PrivateEntityManagerMiddleware (and commit tracking, so both
     * middlewares are stacked as in an application). Savepoints on, also on DBAL 3.
     *
     * @return array{Connection, PrivateEntityManager}
     */
    private function trackedPrivateManager(): array
    {
        $forwarder     = new ForwardingListener();
        $configuration = new DbalConfiguration();
        $configuration->setMiddlewares([new PrivateEntityManagerMiddleware($forwarder, 'bundle_'), new CommitTrackingMiddleware(new CommitLedger())]);
        $connection = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']))->parse($this->url), $configuration);
        if (CommitTrackingDriver::driverCommitReturnsResult()) {
            $connection->setNestTransactionsWithSavepoints(true); // DBAL 3 (always on in DBAL 4, where the call is deprecated)
        }

        $registry          = new TestManagerRegistry($connection, static fn (Connection $connection): EntityManagerInterface => self::entityManager($connection));
        $private           = $this->privateManager(null, $registry);
        $forwarder->target = $private;

        return [$connection, $private];
    }

    /**
     * Audit WD-2: the connection closed with a transaction open after a private flush (ConnectionRepairer,
     * DoctrineTransactionRunner, WorkerConnectionHygiene): the server rolled the write back, so the private manager is
     * stale and replaced: no ghost entity, no silent 0-row UPDATE. Audit WD-3: DBAL 3 too, and commit() still returns
     * the driver's result there through both middlewares.
     */
    private function scenarioAClosedConnectionMakesThePrivateManagerStale(): void
    {
        [$connection, $private] = $this->trackedPrivateManager();

        try {
            $connection->beginTransaction();
            $note = $private->find(BundleNote::class, 1);
            self::assertInstanceOf(BundleNote::class, $note);
            $note->counterId = 41;
            $private->flush();
            $first = $private->live();
            $connection->close();
            self::assertTrue($private->isStale(), 'the transaction went away with its connection');
            self::assertNotSame($first, $private->live());

            $again = $private->find(BundleNote::class, 1);
            self::assertInstanceOf(BundleNote::class, $again);
            self::assertNotSame(41, $again->counterId, 'no ghost of the rolled-back write');

            $connection->beginTransaction();
            $result = (new ReflectionMethod($connection, 'commit'))->invoke($connection);
            if (CommitTrackingDriver::driverCommitReturnsResult()) {
                self::assertTrue($result, 'DBAL 3: Connection::commit() still returns the driver\'s result');
            }

            self::assertFalse($private->isStale());
        } finally {
            $private->reset();
            $connection->close();
        }
    }

    /**
     * Audit WD-R1: the host catches a failed statement inside its transaction, then commits. PostgreSQL answers that
     * COMMIT with a ROLLBACK the driver reports as a success: the private manager's flushed write is gone, so it must be
     * stale (no ghost). Other servers keep the transaction: the write is durable and the manager is not stale. A rollback
     * to a savepoint taken before the failure keeps the transaction on every server.
     */
    private function scenarioAFailedHostStatementBeforeCommit(): void
    {
        [$connection, $private] = $this->trackedPrivateManager();
        $failing                = static function () use ($connection): void {
            try {
                $connection->insert('ddrb_counter', ['id' => 1, 'counter_value' => 0]); // duplicate key
                self::fail('No failure.');
            } catch (UniqueConstraintViolationException) {
            }
        };

        try {
            $connection->beginTransaction();
            $note = $private->find(BundleNote::class, 1);
            self::assertInstanceOf(BundleNote::class, $note);
            $note->counterId = 51;
            $private->flush();
            $failing();
            $connection->commit();

            $durable = (int) $this->freshValue('SELECT counter_id FROM ddrb_note WHERE id = 1') === 51;
            self::assertSame(!$this->isPostgreSql(), $durable);
            self::assertSame(!$durable, $private->isStale(), 'stale exactly when the server rolled the write back');
            $again = $private->find(BundleNote::class, 1);
            self::assertInstanceOf(BundleNote::class, $again);
            self::assertSame($durable ? 51 : 1, $again->counterId, 'no ghost');

            $connection->beginTransaction();
            $again->counterId = 52;
            $private->flush();
            $connection->beginTransaction(); // a savepoint
            $failing();
            $connection->rollBack(); // to the savepoint: the failure is undone
            $connection->commit();
            self::assertFalse($private->isStale());
            self::assertSame(52, (int) $this->freshValue('SELECT counter_id FROM ddrb_note WHERE id = 1'));
        } finally {
            $private->reset();
            $connection->close();
        }
    }

    /** A boundary never fails: a logger or an onClear listener that throws is swallowed, the manager dropped anyway. */
    private function scenarioABoundaryNeverFails(): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        };
        $private = $this->privateManager($logger);
        $note    = $private->find(BundleNote::class, 1);
        self::assertInstanceOf(BundleNote::class, $note);
        $note->counterId = 4;
        $failing         = new class {
            public function onClear(): never
            {
                throw new RuntimeException('onClear listener down');
            }
        };
        $events = $this->manager()->getEventManager(); // shared with the private manager
        $events->addEventListener([Events::onClear], $failing);

        try {
            $private->reset();
        } finally {
            $events->removeEventListener([Events::onClear], $failing);
        }

        self::assertFalse($private->isCreated());
    }

    private static function bundleNamespace(): string
    {
        return 'Nowo\\TransactionDoctrine\\Tests\\Integration\\Database\\Support\\Host';
    }
}
