<?php

declare(strict_types=1);

return [
    'includes' => [
        __DIR__.'/../vendor/phpstan/phpstan-strict-rules/rules.neon',
        __DIR__.'/../vendor/phpstan/phpstan-deprecation-rules/rules.neon',
    ],
    'parameters' => [
        'level' => 6,
        'phpVersion' => 80500,
        'paths' => [
            __DIR__.'/../src',
        ],
        'excludePaths' => [
            __DIR__.'/../src/Kernel.php',
            __DIR__.'/../var/*',
            __DIR__.'/../vendor/*',
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
