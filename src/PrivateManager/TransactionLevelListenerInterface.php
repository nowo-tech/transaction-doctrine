<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\PrivateManager;

/**
 * Told by PrivateEntityManagerConnection how the transactions of the connection end (1.9): the outermost COMMIT, a
 * rollback of a transaction or savepoint, a released savepoint. PrivateEntityManager implements it to know when its
 * identity map may describe rows a rollback undid.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface TransactionLevelListenerInterface
{
    /** The outermost transaction committed. */
    public function connectionCommitted(): void;

    /**
     * A transaction (level 1) or a savepoint (its nesting level) was rolled back; null when the level is unknown (a
     * savepoint the application named itself).
     */
    public function connectionRolledBack(?int $level = 1): void;

    /** A savepoint of this nesting level was released: its rows now belong to the level below. */
    public function savepointReleased(int $level): void;
}
