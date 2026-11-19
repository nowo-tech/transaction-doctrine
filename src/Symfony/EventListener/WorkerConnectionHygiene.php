<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\EventListener;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\Exception\ManagerException;
use Doctrine\ORM\Exception\ORMException;
use Nowo\TransactionDoctrine\ThrowableWalk;
use Nowo\TransactionDoctrine\WriteUnit\DoctrineStateRepairer;
use PDOException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
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

    /**
     * Whether the failure carries a DBAL, driver, PDO or ORM failure (getPrevious() and Messenger's wrapped
     * exceptions), read through the bounded ThrowableWalk (audit UI2-5): each throwable once, at most
     * ThrowableWalk::MAX_NODES, and never throwing (a broken custom wrapper used to replace the failure inside the
     * kernel.exception / WorkerMessageFailedEvent listener, and a wrapper that wrapped itself recursed without end).
     *
     * A truncated walk (the cap reached, or a getWrappedExceptions() that threw) counts as a database failure: the
     * repair it triggers only resets closed managers, rolls back leftover levels after the unit is over and pings the
     * connected connections, so a needless one costs a ping; a missed one would let a closed manager or a broken
     * connection reach the next request or message of the worker.
     */
    public static function isDatabaseFailure(Throwable $failure): bool
    {
        $walk = ThrowableWalk::of($failure);
        foreach ($walk as $current => $chainStart) {
            if ($current instanceof DbalException || $current instanceof DriverException || $current instanceof PDOException
                || $current instanceof ORMException || $current instanceof ManagerException) {
                return true;
            }
        }

        return $walk->getReturn();
    }
}
