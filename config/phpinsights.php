<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenDefineFunctions;
use NunoMaduro\PhpInsights\Domain\Metrics\Style\Style;
use PHP_CodeSniffer\Standards\Generic\Sniffs\WhiteSpace\ArbitraryParenthesesSpacingSniff;
use SlevomatCodingStandard\Sniffs\Classes\ForbiddenPublicPropertySniff;
use SlevomatCodingStandard\Sniffs\ControlStructures\RequireMultiLineConditionSniff;
use SlevomatCodingStandard\Sniffs\ControlStructures\RequireMultiLineTernaryOperatorSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ReturnTypeHintSniff;
use StanislasPoisson\DevTools\Insights;

/*
 * PHP Insights Base Configuration
 * ================
 * The rules come from stanislas-poisson/php-dev-tools (config/phpinsights.php of that package). This file only holds what is specific to
 * Laravel: the preset, the exclusions, the requirements, and the false positives that Eloquent and the enums cause.
 *
 * IMPORTANT:
 * A project extends this configuration and can override any of it.
 * For numeric arrays (remove, add, exclude) always merge using spread operator.
 * Never use array_replace_recursive() - it replaces values by index.
 */
return Insights::config([
    'preset' => 'laravel',

    'exclude' => [
        'tests/Fixtures',
    ],

    // A condition of three parts or more is written on several lines, one part per line, with the operator at the
    // start of the line (handbook, standards/php.md, "Conditions"). The sniff only alerts, it counts characters, not
    // parts: this length is where the third part usually is. Nothing rewrites the code: the form is written by hand.
    'add' => [
        Style::class => [
            RequireMultiLineConditionSniff::class,
            RequireMultiLineTernaryOperatorSniff::class,
        ],
    ],

    'config' => [
        // A group of a condition has its parentheses on their own lines (handbook, "Conditions").
        ArbitraryParenthesesSpacingSniff::class => [
            'ignoreNewlines' => true,
        ],

        // A ternary is always written on several lines: the condition, then `? ...` and `: ...` (handbook, "Conditions").
        RequireMultiLineTernaryOperatorSniff::class => [
            'lineLengthLimit' => 0,
        ],

        RequireMultiLineConditionSniff::class => [
            'minLineLength'                 => 60,
            'booleanOperatorOnPreviousLine' => false,
        ],

        CyclomaticComplexityIsHigh::class => [
            'maxComplexity' => 15,
            'exclude'       => [
                'Console/Commands/Dev/PublishToolingCommand.php',
                'Services/ConfigStubPublisher.php',
            ],
        ],

        // Eloquent and the queue read these properties publicly ($timestamps, $timeout, $tries, promoted job arguments).
        ForbiddenPublicPropertySniff::class => [
            'exclude' => [
                'app/Models',
                'app/Jobs',
            ],
        ],

        // Eloquent relations are typed with generics (BelongsTo<Commune, $this>) that the sniff cannot read.
        ReturnTypeHintSniff::class => [
            'exclude' => [
                'app/Models',
            ],
        ],

        // PHPInsights does not read the methods of an enum and takes them for global functions.
        ForbiddenDefineFunctions::class => [
            'exclude' => [
                'app/Enums',
            ],
        ],
    ],

    'requirements' => [
        'min-quality'      => 80,
        'min-complexity'   => 40,
        'min-architecture' => 75,
        'min-style'        => 0,
    ],
]);
