<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('vendor')
    // Adjust these folders if your DDD structure is different (e.g., src/Domain, src/Infrastructure)
    ->notPath('bin/console')
    ->notPath('public/index.php')
;

return (new PhpCsFixer\Config())
    ->setRules([
        // Base Symfony rules
        '@Symfony' => true,
        '@Symfony:risky' => true,

        // Specific rules for modern PHP (8.0+)
        '@PHP80Migration:risky' => true,
        '@PHP81Migration' => true,
        '@PHP82Migration' => true,
        '@PHP83Migration' => true, // Includes applicable improvements up to PHP 8.4

        // Mandatory strict typing
        'declare_strict_types' => true,

        // Force Yoda Conditions (included in @Symfony, but explicitly set per your rules)
        'yoda_style' => [
            'equal' => true,
            'identical' => true,
            'less_and_greater' => null, // Optional for < and >
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
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile('.php-cs-fixer.cache') // Make sure to add this file to your .gitignore
;
