<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use Closure;

/**
 * The graph of the packages of a lock file: which package is reached through which.
 */
final class PackageGraph
{
    /**
     * Whether a package is only reached through the packages of the group (zairakai/*), by name.
     *
     * @param array<string, mixed> $lock
     * @param array<string, mixed> $manifest
     */
    public static function isInherited(
        array $lock,
        array $manifest,
        string $name,
        string $scope = 'zairakai/',
    ): bool {
        /** @var Closure(mixed): list<string> $names */
        $names = static fn (mixed $requirements): array => array_map(strval(...), array_keys(Json::map($requirements)));

        /** @var array<string, list<string>> $edges */
        $edges = [];

        foreach ([
            ...Json::rows($lock['packages'] ?? []),
            ...Json::rows($lock['packages-dev'] ?? []),
        ] as $package) {
            $edges[Json::text($package, 'name')] = $names($package['require'] ?? []);
        }

        $direct = [
            ...$names($manifest['require'] ?? []),
            ...$names($manifest['require-dev'] ?? []),
        ];

        $ours   = self::reachable(
            $edges,
            array_values(
                array_filter($direct, static fn (string $dependency): bool => ! str_starts_with($dependency, $scope)),
            ),
        );
        $theirs = self::reachable(
            $edges,
            array_values(
                array_filter($direct, static fn (string $dependency): bool => str_starts_with($dependency, $scope)),
            ),
        );

        return isset($theirs[$name]) && ! isset($ours[$name]);
    }

    /**
     * Every package reached from the starting ones, by name.
     *
     * @param array<string, list<string>> $edges
     * @param list<string>                $starts
     *
     * @return array<string, bool>
     */
    private static function reachable(array $edges, array $starts): array
    {
        $seen  = array_fill_keys($starts, true);
        $queue = $starts;

        while ([] !== $queue) {
            foreach ($edges[array_shift($queue)] ?? [] as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[]     = $next;
                }
            }
        }

        return $seen;
    }
}
