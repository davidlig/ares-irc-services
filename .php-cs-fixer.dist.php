<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
    ->notPath('bin/console')
    ->notPath('public/index.php')
    ->notPath('config/reference.php')
;

return (new PhpCsFixer\Config())
    ->setRules([
        // Base Symfony rules
        '@Symfony' => true,
        '@Symfony:risky' => true,

        // Specific rules for modern PHP (8.4+)
        '@PHP80Migration:risky' => true,
        '@PHP81Migration' => true,
        '@PHP82Migration' => true,
        '@PHP83Migration' => true,
        '@PHP84Migration' => true,

        // Mandatory strict typing
        'declare_strict_types' => true,

        // Force Yoda Conditions (included in @Symfony, but explicitly set per your rules)
        'yoda_style' => [
            'equal' => true,
            'identical' => true,
            'less_and_greater' => null,
        ],

        // Arrays and formatting
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],

        // Constructor property promotion formatting (PHP 8.0+)
        'class_attributes_separation' => [
            'elements' => [
                'const' => 'one',
                'method' => 'one',
                'property' => 'one',
                'trait_import' => 'none',
            ],
        ],

        // Imports optimization
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],

        // PHPUnit
        'php_unit_strict' => true,
        'php_unit_construct' => true,

        // Modern PHP 8.4+ type formatting
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_types' => [
            'sort_algorithm' => 'alpha',
            'null_adjustment' => 'always_last',
        ],

        // Cleaner code
        'single_line_empty_body' => true,
        'no_superfluous_phpdoc_tags' => [
            'remove_inheritdoc' => false,
        ],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile('.php-cs-fixer.cache');
