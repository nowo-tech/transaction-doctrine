<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Integration\Database\Support;

use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * An entity manager service reset in place, as DoctrineBundle resets its lazy entity manager: the same object, a new
 * entity manager (and unit of work) behind it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class InPlaceManager extends EntityManagerDecorator
{
    public function swap(EntityManagerInterface $manager): void
    {
        $this->wrapped = $manager;
    }
}
