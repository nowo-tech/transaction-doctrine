<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Support;

use Nowo\TransactionDoctrine\WriteUnit\ResetBetweenAttemptsInterface;

/**
 * Records, in order, what happened around write-unit attempts (resets, events, attempts) in the replay-hook tests.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class Journal
{
    /** @var list<string> */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /** A resetter that writes its name into this journal. */
    public function resetter(string $name): ResetBetweenAttemptsInterface
    {
        return new class($this, $name) implements ResetBetweenAttemptsInterface {
            public function __construct(private readonly Journal $journal, private readonly string $name)
            {
            }

            public function resetBetweenAttempts(): void
            {
                $this->journal->add($this->name);
            }
        };
    }
}
