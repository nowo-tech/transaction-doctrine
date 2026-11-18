<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\WriteUnit;

/**
 * A watermark on the CommitLedger: the count when the mark was taken. committedSince() tells whether anything was
 * committed after it, which is what turns a conflict into CONFLICT_002 (REQ-RUNTIME-003 rule 6, request-level
 * detection) when the commit came from earlier in the same request: the host, another bundle or an earlier unit.
 *
 * The Symfony integration takes one per main request at kernel.request (EventListener\RequestCommitWatermark) and
 * stores it in the request attribute REQUEST_ATTRIBUTE; sub-requests carry the main request's mark. A Messenger
 * handler or a console command can take its own with `new CommitMark($ledger)`.
 *
 * Immutable and scoped to what holds it (one request): the ledger is monotonic and only the difference is read, so a
 * mark never needs a reset in a FrankenPHP worker with kernel reset off.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class CommitMark
{
    /** The request attribute holding the main request's mark. */
    public const REQUEST_ATTRIBUTE = '_nowo_transaction_doctrine_commit_mark';

    /** The ledger count when the mark was taken. */
    public int $count;

    public function __construct(
        private CommitLedger $ledger,
    ) {
        $this->count = $ledger->count();
    }

    /** Whether a COMMIT (or an auto-committed write) succeeded since the mark was taken. */
    public function committedSince(): bool
    {
        return $this->ledger->count() !== $this->count;
    }
}
