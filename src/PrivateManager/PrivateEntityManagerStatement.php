<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Throwable;

use function func_get_args;

/**
 * Prepared statement of PrivateEntityManagerConnection: tells it when the statement fails (on PostgreSQL that aborts
 * the open transaction, audit WD-R1).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class PrivateEntityManagerStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly PrivateEntityManagerConnection $connection)
    {
        parent::__construct($statement);
    }

    /**
     * DBAL 3 passes parameters here; DBAL 4 takes none (the extra argument is ignored).
     *
     * @param mixed $params
     */
    public function execute($params = null): Result
    {
        try {
            return parent::execute(...func_get_args());
        } catch (Throwable $exception) {
            $this->connection->failed();

            throw $exception;
        }
    }
}
