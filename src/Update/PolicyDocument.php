<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Update;

/**
 * The policy document of a project (the held and rejected packages): its content, its validation and its changes.
 */
final class PolicyDocument
{
    /**
     * A policy document as the file is written: two spaces, a final new line.
     *
     * @param array<string, mixed> $doc
     */
    public static function encodePolicy(array $doc): string
    {
        $json = json_encode(
            $doc,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return preg_replace_callback(
            '/^ +/m',
            static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[0]), 2)),
            $json,
        ) . "\n";
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
        return array_values(
            array_filter(
                array_map(
                    static fn (array $entry): string => Json::text($entry, 'package'),
                    Json::rows($doc['entries'] ?? []),
                ),
                static fn (string $name): bool => '' !== $name,
            ),
        );
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
        if (
            ! isset($doc['entries'])
            || ! is_array($doc['entries'])
        ) {
            return [
                '"entries" must be a list.',
            ];
        }

        $errors = [];
        $seen   = [];

        foreach (array_values($doc['entries']) as $index => $item) {
            $entry  = Json::map($item);
            $name   = Json::text($entry, 'package');
            $errors = [
                ...$errors,
                ...self::nameErrors("entries[{$index}]", $name, in_array($name, $seen, true)),
                ...self::entryErrors($entry, "entries[{$index}]"),
            ];
            $seen[] = $name;
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
        $doc['entries'] = array_values(
            array_filter(
                Json::rows($doc['entries'] ?? []),
                static fn (array $entry): bool => ! in_array(
                    Json::text($entry, 'package'),
                    $names,
                    true,
                ),
            ),
        );

        return $doc;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<string>
     */
    private static function entryErrors(array $entry, string $where): array
    {
        $errors = [];

        if ('' === trim(Json::text($entry, 'reason'))) {
            $errors[] = "{$where}: \"reason\" is missing.";
        }

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', Json::text($entry, 'since'))) {
            $errors[] = "{$where}: \"since\" must be a date (YYYY-MM-DD).";
        }

        return array_key_exists('unblock', $entry)
            ? [...$errors, ...self::unblockErrors(Json::map($entry['unblock']), $where)]
            : $errors;
    }

    /**
     * @return list<string>
     */
    private static function nameErrors(string $where, string $name, bool $listedBefore): array
    {
        return match (true) {
            '' === $name  => ["{$where}: \"package\" is missing."],
            $listedBefore => ["{$where}: \"{$name}\" is listed twice."],
            default       => [],
        };
    }

    /**
     * @param array<string, mixed> $unblock
     *
     * @return list<string>
     */
    private static function unblockErrors(array $unblock, string $where): array
    {
        $errors = [];

        if (1 !== preg_match('/^\d+\.\d+\.\d+$/', Json::text($unblock, 'version'))) {
            $errors[] = "{$where}: \"unblock.version\" must be a version (X.Y.Z).";
        }

        $peers = Json::strings($unblock['peers'] ?? null);

        if (
            [] === $peers
            || count($peers) !== count(Json::map($unblock['peers'] ?? null))
        ) {
            $errors[] = "{$where}: \"unblock.peers\" must be a list of packages.";
        }

        return $errors;
    }
}
