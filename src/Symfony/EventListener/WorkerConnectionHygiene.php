<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\EventListener;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\Exception\ManagerException;
use Doctrine\ORM\Exception\ORMException;
use Nowo\TransactionDoctrine\WriteUnit\DoctrineStateRepairer;
use PDOException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;

/**
 * A closed EntityManager never survives into another request or message, and a connection that failed is checked
 * before the next unit (FrankenPHP worker mode, kernel reset off; option `worker_hygiene`):
 *
 * - kernel.request (main, priority 4096): closed managers are reset (no query);
 * - kernel.exception (priority 4096, never answers): a DBAL, driver, PDO or ORM failure marks the request itself;
 * - kernel.terminate (priority -4096): after the response of a marked request, managers are reset, leftover
 *   transaction levels rolled back and each connected connection pinged (closed when it no longer answers);
 * - WorkerMessageFailedEvent (priority -4096): a consumed message that failed the same way is repaired at once.
 *
 * Keeps no state of its own: the mark lives on the request.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class WorkerConnectionHygiene
{
    public const FAILED_ATTRIBUTE = '_nowo_transaction_doctrine_database_failure';

    public function __construct(
        private DoctrineStateRepairer $repairer,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->repairer->resetClosedManagers();
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (self::isDatabaseFailure($event->getThrowable())) {
            $event->getRequest()->attributes->set(self::FAILED_ATTRIBUTE, true);
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ($event->getRequest()->attributes->get(self::FAILED_ATTRIBUTE) === true) {
            $this->repairer->afterDatabaseFailure();
        }
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (self::isDatabaseFailure($event->getThrowable())) {
            $this->repairer->afterDatabaseFailure();
        }
    }

    public static function isDatabaseFailure(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof DbalException || $current instanceof DriverException || $current instanceof PDOException
                || $current instanceof ORMException || $current instanceof ManagerException) {
                return true;
            }

            if ($current instanceof WrappedExceptionsInterface) {
                foreach ($current->getWrappedExceptions(null, true) as $wrapped) {
                    if (self::isDatabaseFailure($wrapped)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
