<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Rector;

use Rector\Configuration\RectorConfigBuilder;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Set\LaravelSetList;
use StanislasPoisson\DevTools\Rector;

/**
 * Base Rector Configuration
 * ================
 * Central configuration for Rector dev-tool.
 * The sets of PHP come from stanislas-poisson/php-dev-tools; this class adds the paths, the skips and the Laravel sets.
 */
final class RectorBaseConfig
{
    /**
     * Build RectorConfig instance.
     *
     * @param list<string> $extraPaths directories added to the ones of the project
     * @param list<string> $extraSkips paths added to the ones that are skipped
     */
    public static function configure(
        string $projectRoot,
        array $extraPaths = [],
        array $extraSkips = [],
    ): RectorConfigBuilder {
        return Rector::configure(
            $projectRoot,
            php: '8.4',
            paths: [],
        )
            ->withPaths(self::resolvePaths($projectRoot, $extraPaths))
            ->withSkip(self::resolveSkips($projectRoot, $extraSkips))
            ->withSets([
                LevelSetList::UP_TO_PHP_84,
                SetList::CODE_QUALITY,
                SetList::DEAD_CODE,
                SetList::EARLY_RETURN,
                SetList::TYPE_DECLARATION,
                LaravelSetList::LARAVEL_110,
                LaravelSetList::LARAVEL_CODE_QUALITY,
            ]);
    }

    /**
     * Resolve source paths
     * ================.
     *
     * @param list<string> $extra
     *
     * @return list<string>
     */
    private static function resolvePaths(string $root, array $extra): array
    {
        $paths = [];

        foreach ([
            'app',
            'src',
            'tests',
        ] as $dir) {
            if (is_dir($root . '/' . $dir)) {
                $paths[] = $root . '/' . $dir;
            }
        }

        return array_merge($paths, $extra);
    }

    /**
     * Resolve paths to skip
     * ================.
     *
     * @param list<string> $extra
     *
     * @return list<string>
     */
    private static function resolveSkips(string $root, array $extra): array
    {
        return array_merge([
            $root . '/tests/Fixtures',
        ], $extra);
    }
}
