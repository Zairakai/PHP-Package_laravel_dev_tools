<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * Typed reading of decoded JSON (composer.json, configuration, GitLab API): a value of another type becomes an empty one.
 */
final class Json
{
    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    public static function requirements(mixed $value): array
    {
        return array_map(static fn (mixed $range): string => is_string($range) ? $range : '', self::map($value));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(self::map(...), $value)) : [];
    }

    /**
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
