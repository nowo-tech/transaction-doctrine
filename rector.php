<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/aliases.php',
    ])
    // Machine-wide cap: at most 2 worker processes; generous timeout for slow (WSL2 / CI) hosts.
    ->withParallel(300, 2, 10)
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    // DBAL 3 declares the transaction getters without native return types (a test double returns null): the casts
    // that read them stay, although DBAL 4's types make them look redundant (1.7.2).
    ->withSkip([
        RecastingRemovalRector::class => [
            __DIR__ . '/src/ConnectionRepairer.php',
            __DIR__ . '/src/DoctrineTransactionRunner.php',
        ],
    ]);
