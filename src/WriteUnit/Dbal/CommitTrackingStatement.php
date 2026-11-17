<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit\Dbal;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

use function func_get_args;

/**
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CommitTrackingStatement extends AbstractStatementMiddleware
{
    public function __construct(Statement $statement, private readonly CommitTrackingConnection $connection, private readonly string $sql)
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
        $result = parent::execute(...func_get_args());
        $this->connection->executed($this->sql);

        return $result;
    }
}
