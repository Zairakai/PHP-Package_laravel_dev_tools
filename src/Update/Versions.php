<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

use Composer\Semver\VersionParser;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The versions of the packages: what a constraint accepts, the latest stable release, the next tag.
 */
final class Versions
{
    /**
     * Whether a Composer constraint accepts a version. A constraint that cannot be read accepts nothing.
     */
    public static function acceptsVersion(string $constraint, string $version): bool
    {
        $versionParser = new VersionParser();

        try {
            return $versionParser->parseConstraints($constraint)->matches(
                $versionParser->parseConstraints('== ' . $version),
            );
        }
        catch (UnexpectedValueException) {
            return false;
        }
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
}
