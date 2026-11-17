<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Support;

use Doctrine\DBAL\Driver\Connection;

/**
 * A driver connection that declares getNativeConnection() (DBAL 4 does; DBAL 3 only documents it), so test doubles
 * can configure it on both majors.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface NativeAwareDriverConnection extends Connection
{
    /**
     * @return object|resource
     */
    public function getNativeConnection();
}
