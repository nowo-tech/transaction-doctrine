<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Closure;
use Nowo\TransactionDoctrine\WriteUnit\AsWriteUnit;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controllers of the replay tests: `marked` is an #[AsWriteUnit] action, `listed` a plain one run through
 * `controller_routes`. Both run the test's step, which receives the attempt number.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NoteController
{
    public int $attempts = 0;

    /**
     * @param Closure(int): void $step
     */
    public function __construct(private readonly Closure $step)
    {
    }

    #[AsWriteUnit]
    public function marked(): Response
    {
        return $this->add();
    }

    public function listed(): Response
    {
        return $this->add();
    }

    private function add(): Response
    {
        ($this->step)(++$this->attempts);

        return new Response('added after ' . $this->attempts);
    }
}
