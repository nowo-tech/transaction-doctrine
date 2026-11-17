<?php

declare(strict_types=1);

namespace Nowo\TransactionDoctrine\Tests\Unit\Support;

use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Proxy;

/**
 * ManagerRegistry over given connection and manager objects (mocks), recording the resets; resetManager() swaps in
 * the replacement when one is given.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RecordingRegistry extends AbstractManagerRegistry
{
    /** @var list<string> */
    public array $resets = [];

    /**
     * @param array<string, object> $connections By name
     * @param array<string, object> $managers By name
     * @param array<string, object> $replacements Manager returned after a reset, by name
     */
    public function __construct(
        private array $connections = [],
        private array $managers = [],
        private readonly array $replacements = [],
    ) {
        $connectionServices = [];
        foreach ($connections as $name => $connection) {
            $connectionServices[$name] = 'connection.' . $name;
        }

        $managerServices = [];
        foreach ($managers as $name => $manager) {
            $managerServices[$name] = 'manager.' . $name;
        }

        parent::__construct('test', $connectionServices, $managerServices, 'default', 'default', Proxy::class);
    }

    protected function getService(string $name): object
    {
        [$kind, $key] = explode('.', $name, 2);

        return $kind === 'connection' ? $this->connections[$key] : $this->managers[$key];
    }

    protected function resetService(string $name): void
    {
        [, $key]        = explode('.', $name, 2);
        $this->resets[] = $key;

        if (isset($this->replacements[$key])) {
            $this->managers[$key] = $this->replacements[$key];
        }
    }
}
