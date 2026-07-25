<?php

declare(strict_types=1);

$vendorDir = is_dir(__DIR__.'/../../vendor') ? __DIR__.'/../../vendor' : __DIR__.'/../../../../../vendor';

return [
    'includes' => [
        $vendorDir.'/phpstan/phpstan-strict-rules/rules.neon',
        $vendorDir.'/phpstan/phpstan-deprecation-rules/rules.neon',
    ],
    'parameters' => [
        'level' => 6,
        'phpVersion' => 80500,
        'paths' => [
            __DIR__.'/../../src',
        ],
        'excludePaths' => [
            __DIR__.'/../../var/*',
            $vendorDir.'/*',
        ],
        'treatPhpDocTypesAsCertain' => false,
        'checkMissingCallableSignature' => true,
        'reportUnmatchedIgnoredErrors' => false,
        'checkTooWideReturnTypesInProtectedAndPublicMethods' => true,
        'checkUninitializedProperties' => true,
        'checkDynamicProperties' => true,
        'inferPrivatePropertyTypeFromConstructor' => true,
        'reportMaybesInMethodSignatures' => true,
        'reportStaticMethodSignatures' => true,
        'checkImplicitMixed' => true,
        'checkBenevolentUnionTypes' => true,
    ],
];
