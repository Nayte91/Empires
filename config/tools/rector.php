<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/../../src',
        __DIR__.'/../../tests',
    ])
    ->withSkip([
        __DIR__.'/../../src/Kernel.php',
        __DIR__.'/../../var',
        __DIR__.'/../../vendor',
        RemoveEmptyClassMethodRector::class => [
            __DIR__.'/../../src/Presentation/Component/Shop.php',
            __DIR__.'/../../src/Presentation/Component/PlayerOrders.php',
            __DIR__.'/../../src/Presentation/Component/CashierTerminal.php',
        ],
    ])
    ->withCache(__DIR__.'/../../var/cache/rector')
    ->withComposerBased(symfony: true)
    ->withSets([
        LevelSetList::UP_TO_PHP_85,

        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,

        SymfonySetList::SYMFONY_CODE_QUALITY,

        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
    ])
    ->withParallel()
;
