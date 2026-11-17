<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony\Fixtures;

use Nowo\TransactionDoctrine\Tests\Unit\Support\FakeDriverException;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ConflictController
{
    public function __construct(private Attempts $attempts)
    {
    }

    public function conflict(): Response
    {
        throw new ConcurrencyConflict(3, TransientFailureKind::Deadlock, null, FakeDriverException::deadlock());
    }

    #[AsWriteUnit]
    public function unit(Request $request): Response
    {
        return $this->deadlockOnce($request);
    }

    public function listed(Request $request): Response
    {
        return $this->deadlockOnce($request);
    }

    #[AsWriteUnit]
    public function always(): Response
    {
        ++$this->attempts->count;

        throw FakeDriverException::lockWaitTimeout();
    }

    /** The first attempt of each request deadlocks; the count lives on the request. */
    private function deadlockOnce(Request $request): Response
    {
        $attempt = $request->attributes->getInt('_test_attempts') + 1;
        $request->attributes->set('_test_attempts', $attempt);
        if ($attempt === 1) {
            throw FakeDriverException::deadlock();
        }

        return new Response('saved after ' . $attempt);
    }
}
