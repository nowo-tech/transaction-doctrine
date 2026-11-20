<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\WriteUnit;

use Nowo\TransactionDoctrine\WriteUnit\KeptEntitiesRestorer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class KeptEntitiesRestorerTest extends TestCase
{
    /** A restore step that throws (a readonly property, a host listener) skips that step only and reports it. */
    public function testAFailingStepIsReportedAndSkipped(): void
    {
        $safely = new ReflectionMethod(KeptEntitiesRestorer::class, 'safely');

        self::assertTrue($safely->invoke(null, static function (): void {
        }));
        self::assertFalse($safely->invoke(null, static function (): never {
            throw new RuntimeException('step failed');
        }));
    }
}
