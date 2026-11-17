<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

/**
 * Message of the Messenger replay test: add a note to the memo's counter.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class AddNote
{
    public function __construct(public int $noteId)
    {
    }
}
