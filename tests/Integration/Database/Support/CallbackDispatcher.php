<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * PSR-14 dispatcher that hands every event to one callback (a WriteUnitRetryEvent listener in the tests).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class CallbackDispatcher implements EventDispatcherInterface
{
    /**
     * @param Closure(object): void $listener
     */
    public function __construct(private Closure $listener)
    {
    }

    public function dispatch(object $event): object
    {
        ($this->listener)($event);

        return $event;
    }
}
