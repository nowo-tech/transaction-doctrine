<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Support;

use RuntimeException;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;

/**
 * A custom Messenger wrapper with no previous exception, whose wrapped exceptions can include itself.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CyclicWrapper extends RuntimeException implements WrappedExceptionsInterface
{
    /** @var list<Throwable> */
    public array $wrapped = [];

    public function __construct()
    {
        parent::__construct('cyclic wrapper');
    }

    /**
     * @return array<Throwable>
     */
    public function getWrappedExceptions(?string $class = null, bool $recursive = false): array
    {
        return $this->wrapped;
    }
}
