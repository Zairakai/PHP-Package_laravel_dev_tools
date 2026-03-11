<?php

declare(strict_types=1);

/**
 * PHP Insights Base Configuration
 * ================================.
 *
 * IMPORTANT: When extending this config, DO NOT use array_replace_recursive()
 * for arrays with numeric keys (like 'remove', 'add', 'exclude'). These will
 * be replaced instead of merged. Use array_merge() or spread operator instead.
 *
 * Use spread operator for numeric arrays (remove, add, exclude).
 *
 * Style authority: PSR-12 is the baseline. Where PSR-12 is silent,
 * pint.json rules take precedence. PHP Insights must not contradict Pint.
 */

use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenNormalClasses;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenTraits;
use PHP_CodeSniffer\Standards\Generic\Sniffs\CodeAnalysis\EmptyStatementSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Strings\UnnecessaryStringConcatSniff;
use PHP_CodeSniffer\Standards\PEAR\Sniffs\WhiteSpace\ScopeClosingBraceSniff;
use PHP_CodeSniffer\Standards\PSR12\Sniffs\Classes\ClassInstantiationSniff;
use PhpCsFixer\Fixer\Basic\BracesFixer;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\Fixer\Operator\BinaryOperatorSpacesFixer;
use PhpCsFixer\Fixer\Operator\NewWithBracesFixer;
use PhpCsFixer\Fixer\Operator\NewWithParenthesesFixer;
use PhpCsFixer\Fixer\StringNotation\SingleQuoteFixer;
use PhpCsFixer\Fixer\Whitespace\MethodChainingIndentationFixer;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousAbstractClassNamingSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousExceptionNamingSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousInterfaceNamingSniff;
use SlevomatCodingStandard\Sniffs\ControlStructures\DisallowYodaComparisonSniff;
use SlevomatCodingStandard\Sniffs\Functions\FunctionLengthSniff;
use SlevomatCodingStandard\Sniffs\Functions\UnusedParameterSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowMixedTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\PropertyTypeHintSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\UselessConstantTypeHintSniff;

return [
    'preset' => 'laravel',

    'ide' => null,

    'exclude' => [
        'vendor',
        'tests/Fixtures',
    ],

    'add' => [],

    'remove' => [
        // ── Naming conventions ────────────────────────────────────────────────
        // PSR-12 does not enforce suffix conventions — these are noisy
        SuperfluousAbstractClassNamingSniff::class,
        SuperfluousExceptionNamingSniff::class,
        SuperfluousInterfaceNamingSniff::class,

        // ── Type hints ────────────────────────────────────────────────────────
        // PSR-12 does not mandate mixed/property type hints at this level
        DisallowMixedTypeHintSniff::class,
        PropertyTypeHintSniff::class,

        // ── Code analysis ────────────────────────────────────────────────────
        // Empty statements are sometimes intentional (catch blocks, etc.)
        EmptyStatementSniff::class,

        // ── Yoda style ───────────────────────────────────────────────────────
        // PSR-12 is silent on yoda comparisons.
        // Pint enforces yoda_style: true — Slevocat's DisallowYodaComparisonSniff
        // would conflict. Pint wins.
        DisallowYodaComparisonSniff::class,

        // ── Binary operator alignment ─────────────────────────────────────────
        // PSR-12 is silent on alignment.
        // Pint enforces binary_operator_spaces with align_single_space.
        // Removing Insights' fixer prevents duplicate/conflicting corrections.
        BinaryOperatorSpacesFixer::class,

        // ── Instantiation & parentheses ──────────────────────────────────────
        // PSR-12 is silent on instantiation style.
        // Pint enforces new_with_parentheses: false — no () on new Class.
        // All three sniffs/fixers below would force () — Pint wins.
        NewWithParenthesesFixer::class,
        NewWithBracesFixer::class,
        ClassInstantiationSniff::class,

        // ── Import ordering ──────────────────────────────────────────────────
        // PSR-12 does not mandate import order.
        // Pint enforces ordered_imports (alpha, const > class > function).
        OrderedImportsFixer::class,

        // ── Quote style ───────────────────────────────────────────────────────
        // PSR-12 is silent. Pint enforces single_quote: true.
        SingleQuoteFixer::class,

        // ── Method chaining ───────────────────────────────────────────────────
        // PSR-12 is silent. Pint enforces method_chaining_indentation.
        MethodChainingIndentationFixer::class,

        // ── Forbidden constructs ─────────────────────────────────────────────
        // Normal classes and traits are valid in Laravel projects
        ForbiddenNormalClasses::class,
        ForbiddenTraits::class,

        // ── Brace style ───────────────────────────────────────────────────────
        // PSR-12 is silent on empty body placement and else continuation style.
        // Our style: `() {}` compact bodies, `else` on new line after `}`.
        // Pint controls both — Insights must not interfere.
        // PEAR\ScopeClosingBraceSniff: flags `() {}` compact constructor bodies
        ScopeClosingBraceSniff::class,
        // BracesFixer: forces `} else {` same line and expands empty bodies
        BracesFixer::class,

        // ── String concatenation ──────────────────────────────────────────────
        // PSR-12 is silent on concat style.
        // Pint splits long lines using `.` concat — this sniff would flag that.
        UnnecessaryStringConcatSniff::class,

        // ── Unused parameter false positives ─────────────────────────────────
        // Pint sometimes flags parameters that are forwarded via variadic calls
        // or used indirectly. Removing globally — noise > value here.
        UnusedParameterSniff::class,

        // ── Useless type hint on constants ────────────────────────────────────
        // PSR-12 is silent. @var on typed constants is informational, not useless.
        UselessConstantTypeHintSniff::class,
    ],

    'config' => [
        CyclomaticComplexityIsHigh::class => [
            'maxComplexity' => 15,
            'exclude'       => [
                'Console/Commands/Dev/PublishToolingCommand.php',
                // ConfigStubPublisher has high aggregate complexity by design:
                // it is a publisher registry with one method per publishable asset.
                'Services/ConfigStubPublisher.php',
            ],
        ],

        FunctionLengthSniff::class => [
            'maxLinesLength' => 50,
        ],

        LineLengthSniff::class => [
            'lineLimit'         => 120,
            'absoluteLineLimit' => 160,
            'ignoreComments'    => true,
        ],
    ],

    'requirements' => [
        'min-quality'            => 80,
        'min-complexity'         => 40,
        'min-architecture'       => 75,
        // Style is enforced exclusively by Pint (pint.json).
        // PHP Insights cannot be configured to match our style choices
        // (CurlyBracesPositionFixer, ControlStructureContinuationPositionFixer
        // are applied by the preset regardless of remove/config entries).
        // Setting to 0 disables style gating here — Pint handles it in CI.
        'min-style'              => 0,
        'disable-security-check' => false,
    ],

    'threads' => null,
];
