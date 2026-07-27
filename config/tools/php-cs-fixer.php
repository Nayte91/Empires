<?php

declare(strict_types=1);
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = new Finder()
    ->in([
        __DIR__.'/../../src',
    ])
    ->exclude(['var', 'vendor'])
;

return new Config()
    ->setRules([
        '@PER-CS3x0' => true,
        '@Symfony' => true,
        '@PhpCsFixer' => true,
        '@Symfony:risky' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP8x4Migration' => true,
        '@PHP8x4Migration:risky' => true,
        'declare_strict_types' => true,
        // Experimental upstream, deliberate here: a lone promoted parameter stays on one line,
        // two or more get a line each.
        'multiline_promoted_properties' => ['minimum_number_of_parameters' => 2],
        'native_function_invocation' => false,
        'php_unit_internal_class' => false,
        'php_unit_test_class_requires_covers' => false,
        'final_internal_class' => false,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'remove_inheritdoc' => false,
        ],
        'use_arrow_functions' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
                'property' => 'none',
                'trait_import' => 'none',
                'case' => 'none',
                'const' => 'none',
            ],
        ],
        'explicit_string_variable' => false,
        'doctrine_annotation_braces' => false,
        'doctrine_annotation_indentation' => false,
        'doctrine_annotation_spaces' => false,
        'no_unused_imports' => true,
        'phpdoc_line_span' => [
            'class' => 'single',
            'const' => 'single',
            'property' => 'single',
            'method' => 'single',
            'case' => 'single',
        ],
        'fully_qualified_strict_types' => [
            'import_symbols' => true,
            'leading_backslash_in_global_namespace' => true,
            'phpdoc_tags' => ['param', 'return', 'var', 'throws'],
        ],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/../../var/cache/phpcsfixer/php-cs-fixer.cache')
    ->setRiskyAllowed(true)
;
