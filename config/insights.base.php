<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenDefineFunctions;
use SlevomatCodingStandard\Sniffs\Classes\ForbiddenPublicPropertySniff;
use SlevomatCodingStandard\Sniffs\TypeHints\ReturnTypeHintSniff;
use StanislasPoisson\DevTools\Insights;

/*
 * PHP Insights Base Configuration
 * ================
 * The rules come from stanislas-poisson/php-dev-tools (config/phpinsights.php). This file only holds what is specific to
 * Laravel: the preset, the exclusions, the requirements, and the false positives that Eloquent and the enums cause.
 *
 * IMPORTANT:
 * A project extends this configuration and can override any of it.
 * For numeric arrays (remove, add, exclude) always merge using spread operator.
 * Never use array_replace_recursive() — it replaces values by index.
 */
return Insights::config([
    'preset' => 'laravel',

    'exclude' => [
        'tests/Fixtures',
    ],

    'config' => [
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
