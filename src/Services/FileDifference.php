<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

/**
 * What differs between the file of the project and the file of the package, in a few words.
 */
final class FileDifference
{
    /**
     * The keys of a JSON file (the rules of pint.json), or the number of lines of another file.
     */
    public static function of(string $project, string $package, string $file): string
    {
        if (str_ends_with($file, '.json')) {
            $mine   = json_decode($project, true);
            $theirs = json_decode($package, true);

            if (is_array($mine) && is_array($theirs)) {
                return self::keys(self::flatten($mine), self::flatten($theirs));
            }
        }

        $mine   = explode("\n", $project);
        $theirs = explode("\n", $package);

        return sprintf(
            '%d line(s) only in the project, %d line(s) only in the package',
            count(array_diff($mine, $theirs)),
            count(array_diff($theirs, $mine)),
        );
    }

    /**
     * The leaves of a decoded JSON, by dotted path, as JSON text.
     *
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = '' === $prefix
                ? (string) $key
                : $prefix . '.' . $key;

            if (
                is_array($value)
                && ! array_is_list($value)
            ) {
                $flat += self::flatten($value, $path);

                continue;
            }

            $flat[$path] = (string) json_encode($value);
        }

        return $flat;
    }

    /**
     * @param array<string, string> $project
     * @param array<string, string> $package
     */
    private static function keys(array $project, array $package): string
    {
        $changed = array_keys(array_filter(
            array_intersect_key($project, $package),
            static fn (string $value, string $key): bool => $value !== $package[$key],
            ARRAY_FILTER_USE_BOTH,
        ));

        return implode('; ', array_filter([
            self::list('only in the project', array_keys(array_diff_key($project, $package))),
            self::list('only in the package', array_keys(array_diff_key($package, $project))),
            self::list('changed', $changed),
        ]));
    }

    /**
     * @param list<string> $keys
     */
    private static function list(string $label, array $keys): string
    {
        return [] === $keys
            ? ''
            : $label . ': ' . implode(', ', $keys);
    }
}
