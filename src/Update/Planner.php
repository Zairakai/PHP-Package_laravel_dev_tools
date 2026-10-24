<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use InvalidArgumentException;

/**
 * The decisions of the automatic update, without any side effect: versions, changes of the manifest, tag to create.
 */
final class Planner
{
    /**
     * The start of the title of the issues opened by the audit.
     */
    public const string ADVISORY_TITLE_PREFIX = 'Security advisory: ';

    /**
     * All the sections of composer.json that hold requirements.
     */
    public const array ALL_SECTIONS = ['require', 'require-dev'];

    /**
     * The sections of composer.json that are published with the package.
     */
    public const array RUNTIME_SECTIONS = ['require'];

    /**
     * The advisories of the JSON report of `composer audit`, one row per advisory.
     *
     * @param array<string, mixed> $report
     *
     * @return list<array{id: string, package: string, title: string, link: string, cve: string, severity: string, range: string}>
     */
    public static function advisories(array $report): array
    {
        $rows = [];

        foreach (Json::map($report['advisories'] ?? []) as $package => $advisories) {
            foreach (Json::rows($advisories) as $advisory) {
                $rows[] = [
                    'id'       => Json::text($advisory, 'advisoryId'),
                    'package'  => Json::text($advisory, 'packageName') ?: $package,
                    'title'    => Json::text($advisory, 'title'),
                    'link'     => Json::text($advisory, 'link'),
                    'cve'      => Json::text($advisory, 'cve'),
                    'severity' => strtolower(Json::text($advisory, 'severity')),
                    'range'    => Json::text($advisory, 'affectedVersions'),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['package'], $a['id']] <=> [$b['package'], $b['id']]);

        return $rows;
    }

    /**
     * The body of the issue opened for an advisory.
     *
     * @param array{id: string, package: string, title: string, link: string, cve: string, severity: string, range: string} $advisory
     */
    public static function advisoryIssueBody(array $advisory, string $pipelineUrl, bool $autoFix): string
    {
        $lines = [
            "{$advisory['title']}.",
            '',
            "- Package: `{$advisory['package']}`",
            '- Affected versions: `' . ('' !== $advisory['range'] ? $advisory['range'] : 'unknown') . '`',
            '- Severity: ' . ('' !== $advisory['severity'] ? $advisory['severity'] : 'not given'),
            '- Advisory: ' . ('' !== $advisory['link'] ? $advisory['link'] : $advisory['id']),
        ];

        if ('' !== $advisory['cve']) {
            $lines[] = "- CVE: {$advisory['cve']}";
        }

        $lines[] = '- Automatic fix: ' . ($autoFix && self::isUrgent($advisory['severity']) ? 'started (an update of the dependencies, taken without waiting)' : 'off for this advisory: update by hand');
        $lines[] = '';
        $lines[] = "Pipeline: {$pipelineUrl}";
        $lines[] = '';
        $lines[] = 'The issue is closed by the next audit that no longer finds this advisory.';

        return implode("\n", $lines);
    }

    /**
     * The title of the issue of an advisory. It is also the key that keeps the issue from being opened twice.
     *
     * @param array{id: string, package: string} $advisory
     */
    public static function advisoryTitle(array $advisory): string
    {
        return self::ADVISORY_TITLE_PREFIX . "{$advisory['package']} ({$advisory['id']})";
    }

    /**
     * The configuration of a project, completed with the defaults.
     *
     * @param array<string, mixed> $user
     *
     * @return array{reject: list<string>, cooldown: string, tag: array{when: string, level: string}, cascade: ?array{groups: list<string>}}
     */
    public static function config(array $user = []): array
    {
        $tag     = Json::map($user['tag'] ?? []);
        $cascade = $user['cascade'] ?? null;

        return [
            'reject'   => Json::strings($user['reject'] ?? []),
            'cooldown' => Json::text($user, 'cooldown') ?: '3d',
            'tag'      => ['when' => Json::text($tag, 'when') ?: 'runtime', 'level' => Json::text($tag, 'level') ?: 'patch'],
            'cascade'  => null === $cascade ? null : ['groups' => Json::strings(Json::map($cascade)['groups'] ?? [])],
        ];
    }

    /**
     * The requirements whose range changed, with the section, the old range and the new one.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     * @param list<string>              $sections
     *
     * @return list<array{section: string, name: string, from: ?string, to: ?string}>
     */
    public static function diffDependencies(?array $before, array $after, array $sections = self::ALL_SECTIONS): array
    {
        $rows = [];

        foreach ($sections as $section) {
            $old     = Json::requirements($before[$section] ?? []);
            $current = Json::requirements($after[$section] ?? []);

            foreach (array_unique([...array_keys($old), ...array_keys($current)]) as $name) {
                $name = (string) $name;

                if (($old[$name] ?? null) !== ($current[$name] ?? null)) {
                    $rows[] = ['section' => $section, 'name' => $name, 'from' => $old[$name] ?? null, 'to' => $current[$name] ?? null];
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['section'], $a['name']] <=> [$b['section'], $b['name']]);

        return $rows;
    }

    /**
     * The changes as a Markdown table, for the merge request and the commit.
     *
     * @param list<array{section: string, name: string, from: ?string, to: ?string}> $rows
     */
    public static function formatChanges(array $rows): string
    {
        $lines = ['| Section | Package | From | To |', '| --- | --- | --- | --- |'];

        foreach ($rows as $row) {
            $lines[] = sprintf('| %s | %s | %s | %s |', $row['section'], $row['name'], $row['from'] ?? '-', $row['to'] ?? '-');
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
            static fn (string $line): bool => 1 === preg_match('/^[+-]/', $line) && 1 !== preg_match('/^(\+\+\+|---)/', $line),
        ));

        if (2 !== count($changed)) {
            return false;
        }

        foreach ($changed as $line) {
            if (1 !== preg_match('/^[+-]\s*ref:\s*\S+\s*$/', $line)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an advisory is fixed without waiting for the next weekly update. Composer does not always give a severity: unknown counts as urgent.
     */
    public static function isUrgent(string $severity): bool
    {
        return in_array($severity, ['', 'high', 'critical'], true);
    }

    /**
     * The highest tag of the form X.Y.Z (a tag with a prefix or a suffix is ignored), or null.
     *
     * @param list<string> $tags
     */
    public static function latestTag(array $tags): ?string
    {
        $versions = array_values(array_filter($tags, static fn (string $tag): bool => 1 === preg_match('/^\d+\.\d+\.\d+$/', $tag)));

        if ([] === $versions) {
            return null;
        }

        usort($versions, strnatcmp(...));

        return $versions[array_key_last($versions)];
    }

    /**
     * The version after `$last` for a level of change (major, minor or patch).
     */
    public static function nextVersion(string $last, string $level): string
    {
        [$major, $minor, $patch] = array_map(intval(...), explode('.', $last));

        return match ($level) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'patch' => $major . '.' . $minor . '.' . ($patch + 1),
            default => throw new InvalidArgumentException("Unknown level {$level}."),
        };
    }

    /**
     * What `tag` has to do: the version to create (or null) and why.
     *
     * @param array{tag: array{when: string, level: string}} $config
     * @param list<string>                                   $commits
     * @param array<string, mixed>|null                      $before
     * @param array<string, mixed>                           $after
     *
     * @return array{version: ?string, reason: string}
     */
    public static function planTag(array $config, ?string $last, array $commits, ?array $before, array $after): array
    {
        if ('never' === $config['tag']['when']) {
            return ['version' => null, 'reason' => 'the project is never tagged by the update'];
        }

        if (null === $last) {
            return ['version' => null, 'reason' => 'there is no tag yet'];
        }

        if ([] === $commits) {
            return ['version' => null, 'reason' => 'no update since the last tag'];
        }

        if ('runtime' === $config['tag']['when'] && ! self::runtimeChanged($before, $after)) {
            return ['version' => null, 'reason' => 'only the development requirements changed: the published archive is the same'];
        }

        return ['version' => self::nextVersion($last, $config['tag']['level']), 'reason' => 'a published range changed'];
    }

    /**
     * True when a range that is published is not the same.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     * @param list<string>              $sections
     */
    public static function runtimeChanged(?array $before, array $after, array $sections = self::RUNTIME_SECTIONS): bool
    {
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
     * The identity and the key used to sign, from the group variables. It fails when one is missing: no unsigned tag.
     *
     * @param array<string, string> $env
     *
     * @return array{name: string, email: string, key: string}
     */
    public static function signerIdentity(array $env): array
    {
        $missing = array_values(array_filter(
            ['GPG_PRIVATE_KEY_B64', 'RELEASE_SIGNER_NAME', 'RELEASE_SIGNER_EMAIL'],
            static fn (string $name): bool => empty($env[$name]),
        ));

        if ([] !== $missing) {
            throw new InvalidArgumentException(implode(', ', $missing) . ' not set: the commits and the tags are signed, see the handbook (versioning).');
        }

        return [
            'name'  => $env['RELEASE_SIGNER_NAME'],
            'email' => $env['RELEASE_SIGNER_EMAIL'],
            'key'   => (string) base64_decode($env['GPG_PRIVATE_KEY_B64'], true),
        ];
    }
}
