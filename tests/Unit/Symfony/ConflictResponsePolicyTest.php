<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Symfony;

use InvalidArgumentException;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConflictResponsePolicyTest extends TestCase
{
    public function testTheDefaultIs409WithOneSecond(): void
    {
        $policy = new ConflictResponsePolicy();

        self::assertSame(409, $policy->status);
        self::assertSame(1, $policy->retryAfter());
        self::assertSame(['Retry-After' => '1'], $policy->headers());
    }

    public function testAHintWinsOverTheConfiguredValue(): void
    {
        $policy = new ConflictResponsePolicy(503, 5);

        self::assertSame(5, $policy->retryAfter());
        self::assertSame(2, $policy->retryAfter(2));
        self::assertSame(0, $policy->retryAfter(-3));
        self::assertSame(['Retry-After' => '2'], $policy->headers(2));
    }

    public function testHttpExceptionCarriesTheStatusAndRetryAfter(): void
    {
        $previous  = new RuntimeException('storage');
        $exception = (new ConflictResponsePolicy(409, 3))->httpException('Retry later.', $previous);

        self::assertSame(409, $exception->getStatusCode());
        self::assertSame(['Retry-After' => '3'], $exception->getHeaders());
        self::assertSame('Retry later.', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(503, (new ConflictResponsePolicy(503))->httpException('x')->getStatusCode());
    }

    public function testOnly409And503AreAccepted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConflictResponsePolicy(500);
    }

    public function testANegativeRetryAfterIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ConflictResponsePolicy(409, -1);
    }
}
