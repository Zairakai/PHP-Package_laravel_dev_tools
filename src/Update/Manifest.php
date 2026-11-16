<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * The changes of composer.json and of the lock file: what changed, what is published with the package.
 */
final class Manifest
{
    /**
     * All the sections of composer.json that hold requirements.
     */
    public const array ALL_SECTIONS = [
        'require',
        'require-dev',
    ];

    /**
     * The sections of composer.json that are published with the package.
     */
    public const array RUNTIME_SECTIONS = [
        'require',
    ];

    /**
     * The requirements whose range changed, with the section, the old range and the new one.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     * @param list<string>              $sections
     *
     * @return list<array{section: string, name: string, from: ?string, to: ?string}>
     */
    public static function diffDependencies(
        ?array $before,
        array $after,
        array $sections = self::ALL_SECTIONS,
    ): array {
        $rows = [];

        foreach ($sections as $section) {
            $old     = Json::requirements($before[$section] ?? []);
            $current = Json::requirements($after[$section] ?? []);

            foreach (array_unique([
                ...array_keys($old),
                ...array_keys($current),
            ]) as $name) {
                $name = (string) $name;

                if (($old[$name] ?? null) !== ($current[$name] ?? null)) {
                    $rows[] = [
                        'section' => $section,
                        'name'    => $name,
                        'from'    => $old[$name]     ?? null,
                        'to'      => $current[$name] ?? null,
                    ];
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => [
            $a['section'],
            $a['name'],
        ] <=> [
            $b['section'],
            $b['name'],
        ]);

        return $rows;
    }

    /**
     * The changes as a Markdown table, for the merge request and the commit.
     *
     * @param list<array{section: string, name: string, from: ?string, to: ?string}> $rows
     */
    public static function formatChanges(array $rows): string
    {
        $lines = [
            '| Section | Package | From | To |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($rows as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $row['section'],
                $row['name'],
                $row['from'] ?? '-',
                $row['to']   ?? '-',
            );
        }

        return implode("\n", $lines);
    }

    /**
     * True when a diff changes one `ref:` line and nothing else. The install of the tools moves the `ref:` of the shared
     * pipeline to the installed version: that is the only change of the pipeline file an update may commit.
     */
    public static function isRefOnlyChange(string $diff): bool
    {
        $changed = array_values(array_filter(
            explode("\n", $diff),
            static fn (string $line): bool => 1 === preg_match('/^[+-]/', $line) && 1 !== preg_match(
                '/^(\+\+\+|---)/',
                $line,
            ),
        ));

        if (2 !== count($changed)) {
            return false;
        }

        return array_all($changed, fn (string $line): bool => 1 === preg_match('/^[+-]\s*ref:\s*\S+\s*$/', $line));
    }

    /**
     * The locked version of every package of a composer.lock, by name.
     *
     * @param array<string, mixed> $lock
     *
     * @return array<string, string>
     */
    public static function lockVersions(array $lock): array
    {
        $versions = [];

        foreach ([
            ...Json::rows($lock['packages'] ?? []),
            ...Json::rows($lock['packages-dev'] ?? []),
        ] as $package) {
            $versions[Json::text($package, 'name')] = Json::text($package, 'version');
        }

        return $versions;
    }

    /**
     * True when a range that is published is not the same.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     * @param list<string>              $sections
     */
    public static function runtimeChanged(
        ?array $before,
        array $after,
        array $sections = self::RUNTIME_SECTIONS,
    ): bool {
        foreach ($sections as $section) {
            $old = Json::requirements($before[$section] ?? []);
            $new = Json::requirements($after[$section] ?? []);
            ksort($old);
            ksort($new);

            if ($old !== $new) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text of dependabot.yml with the block of the ecosystem rewritten: between `# BEGIN dependency-policy (composer)` and
     * `# END dependency-policy (composer)`, one ignore rule per package of the policy (nothing when none). Null without markers.
     *
     * @param list<string> $names
     */
    public static function syncDependabot(
        string $text,
        array $names,
        string $ecosystem,
    ): ?string {
        $begin   = "# BEGIN dependency-policy ({$ecosystem})";
        $end     = "# END dependency-policy ({$ecosystem})";
        $pattern = '/^([ \t]*)' . preg_quote($begin, '/') . '\n.*?^[ \t]*' . preg_quote($end, '/') . '$/ms';

        if (1 !== preg_match(
            $pattern,
            $text,
            $match,
        )) {
            return null;
        }

        $indent = $match[1];
        $rules  = [] === $names ? [] : [
            "{$indent}ignore:",
            ...array_map(static fn (string $name): string => "{$indent}  - dependency-name: \"{$name}\"", $names),
        ];

        return (string) preg_replace_callback($pattern, static fn (): string => implode("\n", [
            "{$indent}{$begin}",
            ...$rules,
            "{$indent}{$end}",
        ]), $text, 1);
    }
}
