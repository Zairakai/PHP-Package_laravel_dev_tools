<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

/**
 * What differs between the root of a project and its config/dev-tools/, one line each.
 *
 * The files and where they must be are the ones of ConfigCopies.
 */
final class ConfigCopiesCheck
{
    /**
     * The problems of a project, or an empty list when the root and config/dev-tools/ agree.
     *
     * A project that has no config/dev-tools/ is not concerned (the package itself, for example).
     *
     * @return list<string>
     */
    public static function problems(string $root): array
    {
        if (! is_dir($root . '/' . ConfigCopies::PUBLISHED)) {
            return [];
        }

        return array_merge(
            self::thinFiles($root),
            self::strayFiles($root),
            self::rootOnlyFiles($root),
        );
    }

    private static function missing(string $name): string
    {
        return sprintf('%s%s is missing', ConfigCopies::PUBLISHED, $name);
    }

    private static function missingAtTheRoot(string $name): string
    {
        return sprintf('%s is missing at the root, where the editor reads it', $name);
    }

    /**
     * @return list<string>
     */
    private static function rootOnlyFiles(string $root): array
    {
        $problems = [];

        foreach (ConfigCopies::ONLY_AT_THE_ROOT as $name) {
            if (! is_file($root . '/' . $name)) {
                $problems[] = self::missingAtTheRoot($name);
            }

            if (is_file($root . '/' . ConfigCopies::PUBLISHED . $name)) {
                $problems[] = sprintf(
                    '%s%s is not read: the file is the one of the root, remove it',
                    ConfigCopies::PUBLISHED,
                    $name,
                );
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function strayFiles(string $root): array
    {
        $problems = [];

        foreach (ConfigCopies::NOT_AT_THE_ROOT as $name) {
            if (is_file($root . '/' . $name)) {
                $problems[] = sprintf(
                    '%s at the root is not read: move its changes to %s%s and remove it',
                    $name,
                    ConfigCopies::PUBLISHED,
                    $name,
                );
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private static function thinFileProblems(string $root, string $name, string $target): array
    {
        return array_values(array_filter([
            str_contains((string) file_get_contents($root . '/' . $name), $target)
                ? null
                : sprintf('%s at the root does not point at %s', $name, $target),
            is_file($root . '/' . ConfigCopies::PUBLISHED . $name)
                ? null
                : self::missing($name),
        ]));
    }

    /**
     * @return list<string>
     */
    private static function thinFiles(string $root): array
    {
        $problems = [];

        foreach (ConfigCopies::THIN_FILES as $name => $target) {
            $thin = $root . '/' . $name;

            if (! is_file($thin)) {
                $problems[] = self::missingAtTheRoot($name);

                continue;
            }

            $problems = [...$problems, ...self::thinFileProblems($root, $name, $target)];
        }

        return $problems;
    }
}
