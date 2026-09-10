<?php

declare(strict_types=1);

/**
 * Coding style.
 *
 * PSR-12 with one deliberate exception: the opening brace of a class or a
 * function stays on the signature line. That is the house style - most of the
 * codebase already writes it that way, and adopting PSR-12 wholesale would
 * rewrite the majority of the project to match a minority of its files.
 *
 * The deviation is configured here rather than remembered, so the fixer keeps
 * it consistent instead of every file being an opinion. Everything else is
 * PSR-12 as published.
 */

$finder = PhpCsFixer\Finder::create()
    // Every directory bin/lint.php checks, plus the fixer's own config, so no
    // PHP file in the project is outside the style it declares.
    ->in([__DIR__ . '/app', __DIR__ . '/bin', __DIR__ . '/public', __DIR__ . '/tests'])
    ->append([__FILE__])
    // Templates, not classes: they are HTML with PHP tags, and the fixer's
    // idea of statement formatting does them no favours.
    ->exclude('Views')
    // The live configuration is untracked and machine-specific; git could not
    // undo a bad reformat of it. main.example.php, which is tracked, is fixed.
    ->notPath('Config/main.php');

return (new PhpCsFixer\Config())
    ->setFinder($finder)
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,

        // The house-style exception. Control structures keep the PSR-12
        // placement; only classes and functions differ.
        'braces_position' => [
            'classes_opening_brace'   => 'same_line',
            'functions_opening_brace' => 'same_line',
        ],

        // Beyond PSR-12, and uncontroversial: these remove noise from diffs
        // rather than impose taste.
        'no_unused_imports'              => true,
        'ordered_imports'                => ['sort_algorithm' => 'alpha'],
        'array_syntax'                   => ['syntax' => 'short'],
        'trailing_comma_in_multiline'    => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_superfluous_elseif'          => true,
        'no_useless_else'                => true,
        'single_quote'                   => true,
        'whitespace_after_comma_in_array' => true,
    ]);
