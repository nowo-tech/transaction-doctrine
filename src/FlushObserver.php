<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine;

/**
 * Notes whether the entity manager flushed while one run() was in progress. One instance per run: it is attached to
 * the manager's event manager for the duration of the run and detached afterwards, so no state outlives the call.
 *
 * @internal
 */
final class FlushObserver
{
    public bool $flushed = false;

    public function postFlush(): void
    {
        $this->flushed = true;
    }
}
