<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * What the policy of a project forbids: the requirements that break it, and the entries that are lifted or inherited.
 */
final class PolicyChecks
{
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

            if (
                null === $range
                || ! Versions::acceptsVersion($range, Json::text($unblock, 'version'))
            ) {
                return false;
            }
        }

        return true;
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
    public static function policyViolations(
        array $blocked,
        ?array $beforeManifest,
        array $afterManifest,
        array $beforeLock,
        array $afterLock,
    ): array {
        return [
            ...self::manifestMoves($blocked, $beforeManifest, $afterManifest),
            ...self::lockMoves($blocked, $beforeLock, $afterLock),
        ];
    }

    /**
     * @param list<string>          $blocked
     * @param array<string, string> $beforeLock
     * @param array<string, string> $afterLock
     *
     * @return list<string>
     */
    private static function lockMoves(array $blocked, array $beforeLock, array $afterLock): array
    {
        $problems = [];

        foreach ($blocked as $name) {
            if (
                isset($beforeLock[$name], $afterLock[$name])
                && $beforeLock[$name] !== $afterLock[$name]
            ) {
                $problems[] = "composer.lock: {$name} moves from {$beforeLock[$name]} to {$afterLock[$name]}.";
            }
        }

        return $problems;
    }

    /**
     * @param list<string>              $blocked
     * @param array<string, mixed>|null $beforeManifest
     * @param array<string, mixed>      $afterManifest
     *
     * @return list<string>
     */
    private static function manifestMoves(array $blocked, ?array $beforeManifest, array $afterManifest): array
    {
        $problems = [];

        foreach (Manifest::diffDependencies($beforeManifest, $afterManifest) as $row) {
            if (
                in_array($row['name'], $blocked, true)
                && null !== $row['from']
                && null !== $row['to']
            ) {
                $problems[] = "composer.json: {$row['name']} moves from {$row['from']} to {$row['to']}.";
            }
        }

        return $problems;
    }
}
