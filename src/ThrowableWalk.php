<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

use Generator;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;

use function array_pop;
use function count;
use function interface_exists;
use function spl_object_id;

/**
 * The bounded walk over everything a failure carries: getPrevious() and, when symfony/messenger is installed, a
 * Messenger wrapper's getWrappedExceptions() (a synchronous HandlerFailedException, a
 * DelayedMessageHandlingException, any custom WrappedExceptionsInterface).
 *
 * - Each throwable is visited once (cycles end), at most MAX_NODES of them, and the queue never holds more than the walk
 *   may still visit (audits TA4-3, TA42-2: a child already known is never queued again).
 * - Order: the throwable, then its whole getPrevious() chain, then the wrapped exceptions (each one with its own chain),
 *   in their order. So the first match of the walk is the first match of the getPrevious() chain when there is one.
 * - Anything a custom wrapper returns that is not a Throwable is ignored (a 409 never becomes a TypeError), and a
 *   getWrappedExceptions() that throws is skipped and counts as a truncation (audit UI-5): the walk never throws.
 * - The generator's return value says whether the walk was truncated: a throwable child was left out because of the
 *   cap (audit TA42-1), or a wrapper's getWrappedExceptions() threw. A caller looking for proof of a commit must then
 *   over-approximate.
 *
 * Stateless: safe in a long-running worker.
 *
 * @internal Bundles use TransientFailureClassifier::findConflict() and ConflictCode
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ThrowableWalk
{
    /** Most throwables walked (a cycle or a fan-out cannot hang a worker). */
    public const MAX_NODES = 256;

    /**
     * @return Generator<Throwable, bool, mixed, bool> Each throwable once (the key) and whether it starts a getPrevious()
     *                                                 chain of its own: the failure itself, or a wrapped exception (the
     *                                                 value); returns whether the walk was truncated
     */
    public static function of(Throwable $failure): Generator
    {
        $messenger = interface_exists(WrappedExceptionsInterface::class);
        $known     = [spl_object_id($failure) => true];
        $stack     = [[$failure, true]];
        $truncated = false;

        while ($stack !== []) {
            [$current, $chainStart] = array_pop($stack);

            yield $current => $chainStart;

            $children = [[$current->getPrevious(), false]];
            if ($messenger && $current instanceof WrappedExceptionsInterface) {
                try {
                    $wrappedExceptions = $current->getWrappedExceptions();
                } catch (Throwable) {
                    // A broken custom wrapper (audit UI-5): what it hides is unknown, so the walk counts as truncated
                    // (CONFLICT_002 when looking for proof of a commit), and nothing escapes the walk.
                    $wrappedExceptions = [];
                    $truncated         = true;
                }

                foreach ($wrappedExceptions as $wrapped) {
                    $children[] = [$wrapped, true];
                }
            }

            // Queued in order (the first children win the cap), pushed in reverse (visited in order).
            $queued = [];
            foreach ($children as [$child, $startsChain]) {
                if (!$child instanceof Throwable || isset($known[$id = spl_object_id($child)])) {
                    continue;
                }

                if (count($known) >= self::MAX_NODES) {
                    $truncated = true;

                    continue;
                }

                $known[$id] = true;
                $queued[]   = [$child, $startsChain];
            }

            while ($queued !== []) {
                $stack[] = array_pop($queued);
            }
        }

        return $truncated;
    }

    /**
     * The first throwable of the walk that is an instance of $class.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public static function first(Throwable $failure, string $class): ?object
    {
        foreach (self::of($failure) as $current => $chainStart) {
            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }
}
