<?php

declare(strict_types=1);

use Pest\Rector\Rules\Pest2ToPest3\UsesToExtendRector;
use Pest\Rector\Set\PestSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    // Bewusst nur tests/ — Produktivcode (app/) wird von Rector nicht angefasst.
    ->withPaths([
        __DIR__.'/tests',
    ])
    ->withSets([
        PestSetList::CODING_STYLE,
    ])
    ->withSkip([
        // tests/Pest.php ist die Bootstrap-Datei der Suite (uses()-Bindings,
        // Expectation-Extensions, TIA-Konfiguration). Die uses()-Schreibweise
        // dort wird bewusst beibehalten; ein automatischer Umbau der
        // Test-Case-Bindung soll nicht Nebeneffekt eines Stil-Laufs sein.
        UsesToExtendRector::class => [
            __DIR__.'/tests/Pest.php',
        ],
    ]);
