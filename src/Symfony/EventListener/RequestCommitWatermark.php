<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Symfony\EventListener;

use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Takes the request's commit watermark (REQ-RUNTIME-003 rule 6, request-level detection): on kernel.request
 * (priority PRIORITY, before routing, the firewall and the host's listeners) the main request gets a CommitMark of the
 * CommitLedger in attribute CommitMark::REQUEST_ATTRIBUTE. A sub-request carries the main request's mark (copied
 * from the RequestStack), so a conflict in a fragment after a commit in the page still answers CONFLICT_002; it takes
 * its own only when no main mark can be found.
 *
 * ConflictCode::forRequest() reads the mark: the bundle's responder and every bundle answering in its own shape get
 * CONFLICT_002 when anything was committed earlier in the same request. Registered when `commit_detection` is on.
 *
 * Keeps no state of its own (FrankenPHP worker mode, kernel reset off): the mark lives on the request and the ledger
 * is monotonic.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class RequestCommitWatermark
{
    public const PRIORITY = 4096;

    public function __construct(
        private CommitLedger $ledger,
        private ?RequestStack $requestStack = null,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->isMainRequest()) {
            $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new CommitMark($this->ledger));

            return;
        }

        // A sub-request built with Request::duplicate() already carries the main request's mark.
        if ($request->attributes->get(CommitMark::REQUEST_ATTRIBUTE) instanceof CommitMark) {
            return;
        }

        $main = $this->requestStack?->getMainRequest();
        $mark = $main instanceof Request ? $main->attributes->get(CommitMark::REQUEST_ATTRIBUTE) : null;
        $request->attributes->set(CommitMark::REQUEST_ATTRIBUTE, $mark instanceof CommitMark ? $mark : new CommitMark($this->ledger));
    }
}
