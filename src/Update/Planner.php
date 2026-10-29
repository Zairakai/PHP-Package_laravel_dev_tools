<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use Closure;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use UnexpectedValueException;

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
     * Whether a Composer constraint accepts a version. A constraint that cannot be read accepts nothing.
     */
    public static function acceptsVersion(string $constraint, string $version): bool
    {
        $versionParser = new VersionParser();

        try {
            return $versionParser->parseConstraints($constraint)->matches($versionParser->parseConstraints('== ' . $version));
        }
        catch (UnexpectedValueException) {
            return false;
        }
    }

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
     * The labels of the issue of an advisory: kind and area, the priority from the severity, and Status::Ready.
     *
     * @param array{severity: string} $advisory
     */
    public static function advisoryLabels(array $advisory, string $base): string
    {
        $priority = match ($advisory['severity']) {
            'critical' => 'Priority::Critical',
            'high'     => 'Priority::High',
            default    => 'Priority::Low',
        };

        return "{$base},{$priority},Status::Ready";
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
     * The one notification of an audit, or null when nothing notable was opened. The advisories of a low severity only get their issue: they are counted, not listed.
     *
     * @param list<array{advisory: array{package: string, id: string, severity: string}, url: string}> $opened
     *
     * @return array{title: string, message: string}|null
     */
    public static function auditAlert(array $opened, string $project): ?array
    {
        $notable = array_values(array_filter($opened, static fn (array $item): bool => self::isNotable($item['advisory']['severity'])));

        if ([] === $notable) {
            return null;
        }

        $lines  = array_map(static fn (array $item): string => "{$item['advisory']['package']} ({$item['advisory']['id']}, " . ('' === $item['advisory']['severity'] ? 'unknown' : $item['advisory']['severity']) . "): {$item['url']}", $notable);
        $silent = count($opened) - count($notable);

        if (0 < $silent) {
            $lines[] = "{$silent} more of a low severity, issue only.";
        }

        return ['title' => 'Security advisories: ' . count($notable) . " new in {$project}", 'message' => implode("\n", $lines)];
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
     * A policy document as the file is written: two spaces, a final new line.
     *
     * @param array<string, mixed> $doc
     */
    public static function encodePolicy(array $doc): string
    {
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return preg_replace_callback('/^ +/m', static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)), $json) . "\n";
    }

    /**
     * The releases of the metadata of Packagist (p2) in full: after the first one, each lists only what changed.
     *
     * @param list<array<string, mixed>> $releases
     *
     * @return list<array<string, mixed>>
     */
    public static function expandReleases(array $releases): array
    {
        $previous = [];
        $full     = [];

        foreach ($releases as $release) {
            $current = $previous;

            foreach ($release as $key => $value) {
                if ('__unset' === $value) {
                    unset($current[$key]);
                }
                else {
                    $current[$key] = $value;
                }
            }

            $full[]   = $current;
            $previous = $current;
        }

        return $full;
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
     * Whether a package is only reached through the packages of the group (zairakai/*), by name.
     *
     * @param array<string, mixed> $lock
     * @param array<string, mixed> $manifest
     */
    public static function isInherited(array $lock, array $manifest, string $name, string $scope = 'zairakai/'): bool
    {
        /** @var Closure(mixed): list<string> $names */
        $names = static fn (mixed $requirements): array => array_map(strval(...), array_keys(Json::map($requirements)));

        /** @var array<string, list<string>> $edges */
        $edges = [];

        foreach ([...Json::rows($lock['packages'] ?? []), ...Json::rows($lock['packages-dev'] ?? [])] as $package) {
            $edges[Json::text($package, 'name')] = $names($package['require'] ?? []);
        }

        $direct = [...$names($manifest['require'] ?? []), ...$names($manifest['require-dev'] ?? [])];

        $ours   = self::reachable($edges, array_values(array_filter($direct, static fn (string $dependency): bool => ! str_starts_with($dependency, $scope))));
        $theirs = self::reachable($edges, array_values(array_filter($direct, static fn (string $dependency): bool => str_starts_with($dependency, $scope))));

        return isset($theirs[$name]) && ! isset($ours[$name]);
    }

    /**
     * Whether the condition of an entry of the policy is met: every package of `peers` accepts the version in its range.
     *
     * @param array<string, mixed>   $entry
     * @param array<string, ?string> $ranges The range in which each peer requires the package, by peer.
     */
    public static function isLifted(array $entry, array $ranges): bool
    {
        $unblock = Json::map($entry['unblock'] ?? null);
        $peers   = Json::strings($unblock['peers'] ?? null);

        if ([] === $peers) {
            return false;
        }

        foreach ($peers as $peer) {
            $range = $ranges[$peer] ?? null;

            if (null === $range || ! self::acceptsVersion($range, Json::text($unblock, 'version'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an advisory is worth a push notification: medium and above. Unknown counts as notable. Composer says medium, npm says moderate.
     */
    public static function isNotable(string $severity): bool
    {
        return in_array($severity, ['', 'medium', 'moderate', 'high', 'critical'], true);
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
     * The highest stable release, or null.
     *
     * @param list<array<string, mixed>> $releases
     *
     * @return array<string, mixed>|null
     */
    public static function latestStable(array $releases): ?array
    {
        $versionParser        = new VersionParser();
        $latest               = null;
        $latestVersion        = null;

        foreach ($releases as $release) {
            $version = Json::text($release, 'version');

            try {
                $normalized = $versionParser->normalize($version);
            }
            catch (UnexpectedValueException) {
                continue;
            }

            if ('stable' !== VersionParser::parseStability($version)) {
                continue;
            }

            if (null === $latestVersion || Comparator::greaterThan($normalized, $latestVersion)) {
                $latest        = $release;
                $latestVersion = $normalized;
            }
        }

        return $latest;
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
     * The locked version of every package of a composer.lock, by name.
     *
     * @param array<string, mixed> $lock
     *
     * @return array<string, string>
     */
    public static function lockVersions(array $lock): array
    {
        $versions = [];

        foreach ([...Json::rows($lock['packages'] ?? []), ...Json::rows($lock['packages-dev'] ?? [])] as $package) {
            $versions[Json::text($package, 'name')] = Json::text($package, 'version');
        }

        return $versions;
    }

    /**
     * The arguments of `composer update` that keep the held back packages at their locked version, also when another package
     * requires them: `-W` would update them with the packages that need them.
     *
     * @param list<string>          $held
     * @param array<string, string> $locked
     *
     * @return list<string>
     */
    public static function heldConstraints(array $held, array $locked): array
    {
        $arguments = [];

        foreach ($held as $name) {
            $version = $locked[$name] ?? '';

            if ('' !== $version) {
                $arguments[] = '--with';
                $arguments[] = "{$name}:=={$version}";
            }
        }

        return $arguments;
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
     * The range in which the latest stable release of a package requires `$name`, from its metadata on Packagist, or null.
     *
     * @param list<array<string, mixed>> $releases
     */
    public static function peerRange(array $releases, string $name): ?string
    {
        $latest = self::latestStable(self::expandReleases($releases));

        if (null === $latest) {
            return null;
        }

        $range = Json::requirements($latest['require'] ?? [])[$name] ?? '';

        return '' === $range ? null : $range;
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
     * The packages of a valid policy document.
     *
     * @param array<string, mixed> $doc
     *
     * @return list<string>
     */
    public static function policyPackages(array $doc): array
    {
        return array_values(array_filter(array_map(static fn (array $entry): string => Json::text($entry, 'package'), Json::rows($doc['entries'] ?? [])), static fn (string $name): bool => '' !== $name));
    }

    /**
     * The packages of the policy whose version changes: in the manifest (a range moves) or in the lock file. A package that is
     * added or removed is not an update.
     *
     * @param list<string>              $blocked
     * @param array<string, mixed>|null $beforeManifest
     * @param array<string, mixed>      $afterManifest
     * @param array<string, string>     $beforeLock
     * @param array<string, string>     $afterLock
     *
     * @return list<string>
     */
    public static function policyViolations(array $blocked, ?array $beforeManifest, array $afterManifest, array $beforeLock, array $afterLock): array
    {
        $problems = [];

        foreach (self::diffDependencies($beforeManifest, $afterManifest) as $row) {
            if (in_array($row['name'], $blocked, true) && null !== $row['from'] && null !== $row['to']) {
                $problems[] = "composer.json: {$row['name']} moves from {$row['from']} to {$row['to']}.";
            }
        }

        foreach ($blocked as $name) {
            if (isset($beforeLock[$name], $afterLock[$name]) && $beforeLock[$name] !== $afterLock[$name]) {
                $problems[] = "composer.lock: {$name} moves from {$beforeLock[$name]} to {$afterLock[$name]}.";
            }
        }

        return $problems;
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

    /**
     * The text of dependabot.yml with the block of the ecosystem rewritten: between `# BEGIN dependency-policy (composer)` and
     * `# END dependency-policy (composer)`, one ignore rule per package of the policy (nothing when none). Null without markers.
     *
     * @param list<string> $names
     */
    public static function syncDependabot(string $text, array $names, string $ecosystem): ?string
    {
        $begin   = "# BEGIN dependency-policy ({$ecosystem})";
        $end     = "# END dependency-policy ({$ecosystem})";
        $pattern = '/^([ \t]*)' . preg_quote($begin, '/') . '\n.*?^[ \t]*' . preg_quote($end, '/') . '$/ms';

        if (1 !== preg_match($pattern, $text, $match)) {
            return null;
        }

        $indent = $match[1];
        $rules  = [] === $names ? [] : ["{$indent}ignore:", ...array_map(static fn (string $name): string => "{$indent}  - dependency-name: \"{$name}\"", $names)];

        return (string) preg_replace_callback($pattern, static fn (): string => implode("\n", ["{$indent}{$begin}", ...$rules, "{$indent}{$end}"]), $text, 1);
    }

    /**
     * The problems of a policy document, as a list of sentences (empty when it is valid).
     *
     * @param array<string, mixed> $doc
     *
     * @return list<string>
     */
    public static function validatePolicy(array $doc): array
    {
        if (! isset($doc['entries']) || ! is_array($doc['entries'])) {
            return ['"entries" must be a list.'];
        }

        $errors = [];
        $seen   = [];

        foreach (array_values($doc['entries']) as $index => $item) {
            $entry = Json::map($item);
            $where = "entries[{$index}]";
            $name  = Json::text($entry, 'package');

            if ('' === $name) {
                $errors[] = "{$where}: \"package\" is missing.";
            }
            elseif (in_array($name, $seen, true)) {
                $errors[] = "{$where}: \"{$name}\" is listed twice.";
            }

            $seen[] = $name;

            if ('' === trim(Json::text($entry, 'reason'))) {
                $errors[] = "{$where}: \"reason\" is missing.";
            }

            if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', Json::text($entry, 'since'))) {
                $errors[] = "{$where}: \"since\" must be a date (YYYY-MM-DD).";
            }

            if (array_key_exists('unblock', $entry)) {
                $unblock = Json::map($entry['unblock']);

                if (1 !== preg_match('/^\d+\.\d+\.\d+$/', Json::text($unblock, 'version'))) {
                    $errors[] = "{$where}: \"unblock.version\" must be a version (X.Y.Z).";
                }

                $peers = Json::strings($unblock['peers'] ?? null);

                if ([] === $peers || count($peers) !== count(Json::map($unblock['peers'] ?? null))) {
                    $errors[] = "{$where}: \"unblock.peers\" must be a list of packages.";
                }
            }
        }

        return $errors;
    }

    /**
     * A policy document without the entries of some packages.
     *
     * @param array<string, mixed> $doc
     * @param list<string>         $names
     *
     * @return array<string, mixed>
     */
    public static function withoutEntries(array $doc, array $names): array
    {
        $doc['entries'] = array_values(array_filter(Json::rows($doc['entries'] ?? []), static fn (array $entry): bool => ! in_array(Json::text($entry, 'package'), $names, true)));

        return $doc;
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
