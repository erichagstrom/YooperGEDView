<?php

declare(strict_types=1);

// Formatting rules for all PHP outside app/. See docs/CODING-STANDARDS.md.

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude(['app', 'vendor'])
    ->ignoreDotFiles(false)
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@PER-CS' => true,
        '@PER-CS:risky' => true,
        '@PHP83Migration' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'final_class' => true,
        'void_return' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'no_superfluous_phpdoc_tags' => true,
        'modernize_strpos' => true,
        'php_unit_strict' => true,
    ]);
