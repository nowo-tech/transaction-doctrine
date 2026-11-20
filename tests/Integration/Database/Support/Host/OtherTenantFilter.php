<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support\Host;

/**
 * The same filter under another class (a filter name registered again with a new class).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class OtherTenantFilter extends TenantFilter
{
}
