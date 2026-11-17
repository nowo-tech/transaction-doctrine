<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logging that can never break the failure path it observes: a logger that throws is ignored, so the original
 * exception (and the repair that follows it) is never replaced. Contexts hold class names, codes and counters only,
 * never SQL, parameters or exception messages (a DBAL message can embed both).
 *
 * @internal
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SafeLog
{
    /**
     * @param 'debug'|'notice'|'warning' $level
     * @param array<string, scalar|null> $context
     */
    public static function log(LoggerInterface $logger, string $level, string $message, array $context): void
    {
        try {
            $logger->log($level, $message, $context);
        } catch (Throwable) {
            // Observability must not alter behaviour.
        }
    }

    /**
     * @return array{exception_class: class-string, exception_code: int|string}
     */
    public static function throwable(Throwable $throwable): array
    {
        return ['exception_class' => $throwable::class, 'exception_code' => $throwable->getCode()];
    }
}
