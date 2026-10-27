<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = new Finder()
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
    ->notPath('bin/console')
    ->notPath('public/index.php')
    ->notPath('config/reference.php')
;

return new Config()
    ->setRules([
        // Base Symfony rules
        '@Symfony' => true,

        // Specific rules for modern PHP (8.5+)
        '@PHP8x5Migration' => true,

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

        // Keep constructors and magic methods together before visibility groups.
        'ordered_class_elements' => [
            'order' => ['use_trait', 'case', 'constant', 'property', 'construct', 'destruct', 'magic', 'method_public', 'method_protected', 'method_private'],
            'sort_algorithm' => 'none',
        ],

        // Imports optimization
        'native_function_invocation' => [
            'include' => ['@internal'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'fully_qualified_strict_types' => [
            'import_symbols' => true,
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
        'no_redundant_readonly_property' => true,
        'no_superfluous_phpdoc_tags' => [
            'remove_inheritdoc' => false,
        ],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile('.php-cs-fixer.cache');
