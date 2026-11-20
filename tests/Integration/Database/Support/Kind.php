<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

/**
 * A backed enum used as an identifier (audit VQ6-4).
 */
enum Kind: string
{
    case Alpha = 'alpha';
    case Beta  = 'beta';
}
