<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Support;

use LogicException;
use RuntimeException;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;

/**
 * A custom Messenger wrapper with no previous exception, whose wrapped exceptions can include itself, or values that
 * are not throwables at all (a broken custom implementation).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class CyclicWrapper extends RuntimeException implements WrappedExceptionsInterface
{
    /** @var list<mixed> */
    public array $wrapped = [];

    /** When true, getWrappedExceptions() throws (a broken custom implementation). */
    public bool $throws = false;

    public function __construct()
    {
        parent::__construct('cyclic wrapper');
    }

    /**
     * @return array<Throwable>
     */
    public function getWrappedExceptions(?string $class = null, bool $recursive = false): array
    {
        if ($this->throws) {
            throw new LogicException('broken wrapper');
        }

        /** @var array<Throwable> $wrapped deliberately not checked: a broken implementation is what is tested */
        $wrapped = $this->wrapped;

        return $wrapped;
    }
}
