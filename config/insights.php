<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\MethodCyclomaticComplexityIsHigh;
use SlevomatCodingStandard\Sniffs\Functions\UnusedParameterSniff;

/**
 * PHP Insights Package Override
 * ================
 * Extends the base configuration from laravel-dev-tools.
 *
 * IMPORTANT:
 * For numeric arrays (remove, add, exclude) always merge using spread operator.
 * Never use array_replace_recursive() — it replaces values by index.
 */

/**
 *  Load base configuration
 * ================
 *  Supports:
 *  - Normal Laravel install
 *  - Orchestra Testbench
 *  - Local project override.
 */
$baseConfig  = [];
$currentFile = __FILE__;

$possiblePaths = [
    dirname(__DIR__) . '/vendor/zairakai/laravel-dev-tools/config/insights.base.php',
    dirname(__DIR__, 5) . '/config/insights.base.php',
    dirname(__DIR__) . '/config/insights.base.php',
];

foreach ($possiblePaths as $path) {
    if (
        file_exists($path)
        && realpath($path) !== realpath($currentFile)
    ) {
        $baseConfig = require $path;

        break;
    }
}

/*
 *  Package specific tuning
 * ================
 *  Adjust rules that are noisy for Composer plugins and tooling classes.
 */

/*
 * High class complexity is acceptable for plugin orchestration classes
 */
$baseConfig['config'][CyclomaticComplexityIsHigh::class] = [
    ...($baseConfig['config'][CyclomaticComplexityIsHigh::class] ?? []),
    'exclude' => [
        ...($baseConfig['config'][CyclomaticComplexityIsHigh::class]['exclude'] ?? []),
        'Console/Commands/Dev/PublishToolingCommand.php',
        'Composer/DevToolsPlugin.php',
        // GitlabCiSynchronizer has 13 small methods (CC ≤ 5 each) — class total
        // inevitably exceeds 15 without any method being genuinely complex.
        'Services/GitlabCiSynchronizer.php',
    ],
];

/*
 * Plugin entrypoints legitimately receive unused parameters
 * (Composer event signatures cannot be changed)
 */
$baseConfig['config'][UnusedParameterSniff::class] = [
    ...($baseConfig['config'][UnusedParameterSniff::class] ?? []),
    'exclude' => [
        ...($baseConfig['config'][UnusedParameterSniff::class]['exclude'] ?? []),
        'Composer/DevToolsPlugin.php',
    ],
];

/*
 * Method complexity acceptable in orchestrator classes
 */
$baseConfig['config'][MethodCyclomaticComplexityIsHigh::class] = [
    ...($baseConfig['config'][MethodCyclomaticComplexityIsHigh::class] ?? []),
    'exclude' => [
        ...($baseConfig['config'][MethodCyclomaticComplexityIsHigh::class]['exclude'] ?? []),
        'Composer/DevToolsPlugin.php',
    ],
];

return $baseConfig;
