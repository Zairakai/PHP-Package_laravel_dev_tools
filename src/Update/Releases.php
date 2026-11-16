<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use UnexpectedValueException;

/**
 * The releases of a package on Packagist: which are stable, the latest, the tag to start from.
 */
final class Releases
{
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
     * The highest stable release, or null.
     *
     * @param list<array<string, mixed>> $releases
     *
     * @return array<string, mixed>|null
     */
    public static function latestStable(array $releases): ?array
    {
        $versionParser = new VersionParser();
        $latest        = null;
        $latestVersion = null;

        foreach ($releases as $release) {
            $normalized = self::stableVersion($versionParser, Json::text($release, 'version'));

            if (
                null !== $normalized
                && (
                    null === $latestVersion
                    || Comparator::greaterThan($normalized, $latestVersion)
                )
            ) {
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
        $versions = array_values(
            array_filter($tags, static fn (string $tag): bool => 1 === preg_match('/^\d+\.\d+\.\d+$/', $tag)),
        );

        if ([] === $versions) {
            return null;
        }

        usort($versions, strnatcmp(...));

        return $versions[array_key_last($versions)];
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

        return '' === $range
            ? null
            : $range;
    }

    /**
     * The normalized version, or null when it cannot be read or is not stable.
     */
    private static function stableVersion(VersionParser $versionParser, string $version): ?string
    {
        try {
            $normalized = $versionParser->normalize($version);
        }
        catch (UnexpectedValueException) {
            return null;
        }

        return 'stable' === VersionParser::parseStability($version)
            ? $normalized
            : null;
    }
}
