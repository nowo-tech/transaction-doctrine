<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use Nowo\TransactionDoctrine\Symfony\EventListener\RequestCommitWatermark;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RequestCommitWatermarkTest extends TestCase
{
    public function testAMainRequestGetsAFreshMarkEveryTime(): void
    {
        $ledger    = new CommitLedger();
        $watermark = new RequestCommitWatermark($ledger);
        $ledger->record();

        $first = Request::create('/');
        $this->dispatch($watermark, $first, HttpKernelInterface::MAIN_REQUEST);
        $mark = $first->attributes->get(CommitMark::REQUEST_ATTRIBUTE);
        self::assertInstanceOf(CommitMark::class, $mark);
        self::assertSame(1, $mark->count);
        self::assertFalse($mark->committedSince());

        $ledger->record();
        self::assertTrue($mark->committedSince());

        // The next request of the same worker starts from the current count: the earlier commit does not leak into it.
        $second = Request::create('/');
        $this->dispatch($watermark, $second, HttpKernelInterface::MAIN_REQUEST);
        $next = $second->attributes->get(CommitMark::REQUEST_ATTRIBUTE);
        self::assertInstanceOf(CommitMark::class, $next);
        self::assertSame(2, $next->count);
        self::assertFalse($next->committedSince());
    }

    public function testASubRequestCarriesTheMainRequestsMark(): void
    {
        $ledger    = new CommitLedger();
        $stack     = new RequestStack();
        $watermark = new RequestCommitWatermark($ledger, $stack);
        $main      = Request::create('/page');
        $stack->push($main);
        $this->dispatch($watermark, $main, HttpKernelInterface::MAIN_REQUEST);
        $ledger->record();

        $fragment = Request::create('/_fragment');
        $stack->push($fragment);
        $this->dispatch($watermark, $fragment, HttpKernelInterface::SUB_REQUEST);

        self::assertSame($main->attributes->get(CommitMark::REQUEST_ATTRIBUTE), $fragment->attributes->get(CommitMark::REQUEST_ATTRIBUTE));
        $mark = $fragment->attributes->get(CommitMark::REQUEST_ATTRIBUTE);
        self::assertInstanceOf(CommitMark::class, $mark);
        self::assertTrue($mark->committedSince(), 'The commit in the page before the fragment counts.');
    }

    public function testADuplicatedSubRequestKeepsTheMarkItCarries(): void
    {
        $ledger    = new CommitLedger();
        $watermark = new RequestCommitWatermark($ledger, new RequestStack());
        $main      = Request::create('/page');
        $this->dispatch($watermark, $main, HttpKernelInterface::MAIN_REQUEST);

        $forward = $main->duplicate();
        $this->dispatch($watermark, $forward, HttpKernelInterface::SUB_REQUEST);

        self::assertSame($main->attributes->get(CommitMark::REQUEST_ATTRIBUTE), $forward->attributes->get(CommitMark::REQUEST_ATTRIBUTE));
    }

    public function testASubRequestWithoutAMainMarkTakesItsOwn(): void
    {
        $ledger = new CommitLedger();
        $ledger->record();
        $stack = new RequestStack();
        $main  = Request::create('/page');
        $main->attributes->set(CommitMark::REQUEST_ATTRIBUTE, new stdClass());
        $stack->push($main);

        foreach ([new RequestCommitWatermark($ledger), new RequestCommitWatermark($ledger, new RequestStack()), new RequestCommitWatermark($ledger, $stack)] as $watermark) {
            $sub = Request::create('/_fragment');
            $this->dispatch($watermark, $sub, HttpKernelInterface::SUB_REQUEST);
            $mark = $sub->attributes->get(CommitMark::REQUEST_ATTRIBUTE);
            self::assertInstanceOf(CommitMark::class, $mark);
            self::assertSame(1, $mark->count);
        }
    }

    private function dispatch(RequestCommitWatermark $watermark, Request $request, int $type): void
    {
        $watermark->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type));
    }
}
