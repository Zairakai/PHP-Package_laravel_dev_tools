<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Rector;

use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Set\LaravelSetList;

/**
 * Base Rector Configuration
 * ================
 * Central configuration for Rector dev-tool.
 * Paths, skips, sets, and parallelization defined here.
 */
final class RectorBaseConfig
{
    /**
     * Build RectorConfig instance.
     */
    public static function configure(
        string $projectRoot,
        array $extraPaths = [],
        array $extraSkips = [],
    ) {
        return RectorConfig::configure()
            ->withPaths(self::resolvePaths($projectRoot, $extraPaths))
            ->withSkip(self::resolveSkips($projectRoot, $extraSkips))
            ->withPhpSets(php83: true)
            ->withPreparedSets(
                deadCode: true,
                codeQuality: true,
                codingStyle: true,
                typeDeclarations: true,
                privatization: true,
                naming: true,
                instanceOf: true,
                earlyReturn: true,
            )
            ->withSets([
                LevelSetList::UP_TO_PHP_83,
                SetList::CODE_QUALITY,
                SetList::DEAD_CODE,
                SetList::EARLY_RETURN,
                SetList::TYPE_DECLARATION,
                LaravelSetList::LARAVEL_110,
                LaravelSetList::LARAVEL_CODE_QUALITY,
            ])
            ->withParallel();
    }

    /**
     * Resolve source paths
     * ================.
     */
    private static function resolvePaths(string $root, array $extra): array
    {
        $paths = [];

        foreach (['app', 'src', 'tests'] as $dir) {
            if (is_dir($root . '/' . $dir)) {
                $paths[] = $root . '/' . $dir;
            }
        }

        return array_merge($paths, $extra);
    }

    /**
     * Resolve paths to skip
     * ================.
     */
    private static function resolveSkips(string $root, array $extra): array
    {
        return array_merge([
            $root . '/tests/Fixtures',
            AddOverrideAttributeToOverriddenMethodsRector::class,
            EncapsedStringsToSprintfRector::class,
        ], $extra);
    }
}
