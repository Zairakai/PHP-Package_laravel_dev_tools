<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

/**
 * The files of the root that an IDE or an extension reads, and their link with config/dev-tools/.
 *
 * A project configures its tools in config/dev-tools/. The root holds a thin file that points there (phpstan.neon,
 * .markdownlint.json), or, when a file cannot point at another one, a copy of the file of config/dev-tools/ (pint.json,
 * phpunit.xml, .markdownlintignore). The scripts read config/dev-tools/, the editor reads the root: the two must say the
 * same. This class writes the copies and tells what differs.
 *
 * PHPUnit reads the paths of its file from the folder of that file: the one of config/dev-tools/ goes up two folders
 * (`../../src`), the copy of the root does not (`src`). The two are equivalent when only that prefix differs.
 */
final class ConfigCopies
{
    /**
     * The files that are copied from config/dev-tools/ to the root.
     */
    public const array COPIES = [
        'pint.json',
        'phpunit.xml',
        '.markdownlintignore',
    ];

    /**
     * The files that no editor reads: the configuration is in config/dev-tools/ only, and a file of the root would say
     * something else than the commands read.
     */
    public const array NOT_AT_THE_ROOT = [
        'rector.php',
        'phpinsights.php',
    ];

    /**
     * The thin files of the root, and what each must point at.
     */
    public const array THIN_FILES = [
        'phpstan.neon'       => 'config/dev-tools/phpstan.neon',
        '.markdownlint.json' => './config/dev-tools/.markdownlint.json',
    ];

    private const string PUBLISHED = 'config/dev-tools/';

    /**
     * The content that the copy of the root must have, from the file of config/dev-tools/.
     */
    public static function expectedCopy(string $name, string $published): string
    {
        return 'phpunit.xml' === $name
            ? self::phpunitForTheRoot($published)
            : $published;
    }

    /**
     * The phpunit.xml of config/dev-tools/ from the one of the package, whose paths are the ones of the root.
     */
    public static function phpunitForConfig(string $xml): string
    {
        return self::convertPhpunitPaths(
            $xml,
            static fn (string $path): string => '../../' . $path,
        );
    }

    /**
     * The phpunit.xml of the root from the one of config/dev-tools/.
     */
    public static function phpunitForTheRoot(string $xml): string
    {
        return self::convertPhpunitPaths(
            $xml,
            static fn (string $path): string => (string) preg_replace('#^(\\.\\./)+#', '', $path),
        );
    }

    /**
     * The problems of a project, one line each, or an empty list when the root and config/dev-tools/ agree.
     *
     * A project that has no config/dev-tools/ is not concerned (the package itself, for example).
     *
     * @return list<string>
     */
    public static function problems(string $root): array
    {
        if (! is_dir($root . '/' . self::PUBLISHED)) {
            return [];
        }

        $problems = [];

        foreach (self::COPIES as $name) {
            $published = $root . '/' . self::PUBLISHED . $name;
            $copy      = $root . '/' . $name;

            if (! is_file($published)) {
                $problems[] = sprintf(
                    '%s%s is missing',
                    self::PUBLISHED,
                    $name,
                );

                continue;
            }

            if (! is_file($copy)) {
                $problems[] = sprintf('%s is missing at the root, where the editor reads it', $name);

                continue;
            }

            if (self::expectedCopy($name, (string) file_get_contents($published)) !== (string) file_get_contents($copy)) {
                $problems[] = sprintf(
                    '%s at the root differs from %s%s',
                    $name,
                    self::PUBLISHED,
                    $name,
                );
            }
        }

        foreach (self::THIN_FILES as $name => $target) {
            $thin = $root . '/' . $name;

            if (! is_file($thin)) {
                $problems[] = sprintf('%s is missing at the root, where the editor reads it', $name);

                continue;
            }

            if (! str_contains((string) file_get_contents($thin), $target)) {
                $problems[] = sprintf(
                    '%s at the root does not point at %s',
                    $name,
                    $target,
                );
            }

            if (! is_file($root . '/' . self::PUBLISHED . $name)) {
                $problems[] = sprintf(
                    '%s%s is missing',
                    self::PUBLISHED,
                    $name,
                );
            }
        }

        foreach (self::NOT_AT_THE_ROOT as $name) {
            if (is_file($root . '/' . $name)) {
                $problems[] = sprintf(
                    '%s at the root is not read: move its changes to %s%s and remove it',
                    $name,
                    self::PUBLISHED,
                    $name,
                );
            }
        }

        return $problems;
    }

    /**
     * Writes again the copies of the root from config/dev-tools/.
     *
     * @param list<string>                 $names  the copies to write; all of them when empty
     * @param callable(string): mixed|null $before called with the path of a copy that exists, before it is written over
     *
     * @return list<string> the names of the files written
     */
    public static function sync(
        string $root,
        array $names = [],
        ?callable $before = null,
    ): array {
        $written = [];

        foreach (self::COPIES as $name) {
            if ([] !== $names && ! in_array(
                $name,
                $names,
                true,
            )) {
                continue;
            }

            $published = $root . '/' . self::PUBLISHED . $name;

            if (! is_file($published)) {
                continue;
            }

            $content = self::expectedCopy($name, (string) file_get_contents($published));
            $copy    = $root . '/' . $name;

            if (null !== $before && is_file($copy) && (string) file_get_contents($copy) !== $content) {
                $before($copy);
            }

            file_put_contents($copy, $content);

            $written[] = $name;
        }

        return $written;
    }

    /**
     * Applies $convert to every path of a phpunit.xml, by the names of the PHPUnit schema.
     *
     * - The attributes that hold a path: bootstrap, cacheDirectory, extensionsDirectory, outputDirectory, outputFile,
     *   baseline, testSuiteLoaderFile and xsi:noNamespaceSchemaLocation (the schema that the editor reads).
     * - The elements that hold a path as text: directory, file, includePath, and exclude when it holds text (an exclude
     *   with directory and file inside is left to them).
     *
     * A stream (`php://stdout`), an absolute path and a drive (`C:\`) are not paths of the project. A comment and a
     * CDATA section are left as they are, and so is the layout of the file: the DOM would write the root element on one
     * line, and the file is edited by the project.
     *
     * @param callable(string): string $convert
     */
    private static function convertPhpunitPaths(string $xml, callable $convert): string
    {
        $convertPath = (static fn (string $path): string => 1 === preg_match('~^(?:[a-z][a-z0-9+.-]*://|/|[A-Za-z]:[\\\\/])~', $path)
            ? $path
            : $convert($path));

        // The parts that are not comments or CDATA, which come back in the order of the file
        $parts = preg_split('~(<!--.*?-->|<!\[CDATA\[.*?\]\]>)~s', $xml, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (false === $parts) {
            return $xml;
        }

        foreach ($parts as $index => $part) {
            if (1 === $index % 2) {
                continue;
            }

            // The attributes, in the start tags
            $part = (string) preg_replace_callback(
                '~(?<=\s)(?<name>(?:[A-Za-z_][\w.-]*:)?(?:bootstrap|cacheDirectory|extensionsDirectory|outputDirectory'
                . '|outputFile|baseline|testSuiteLoaderFile|noNamespaceSchemaLocation))(?<equal>\s*=\s*)(?<quote>["\'])(?<value>[^"\']*)\k<quote>~',
                static fn (array $m): string => $m['name'] . $m['equal'] . $m['quote']
                    . ('' === trim($m['value']) ? $m['value'] : $convertPath($m['value'])) . $m['quote'],
                $part,
            );

            // The elements with a path as text
            $parts[$index] = (string) preg_replace_callback(
                '~(?<open><(?<name>directory|file|includePath|exclude)\b[^>]*>)(?<lead>\s*)(?<path>[^<]*?)(?<trail>\s*)(?<close></\k<name>>)~',
                static fn (array $m): string => '' === $m['path']
                    ? $m[0]
                    : $m['open'] . $m['lead'] . $convertPath($m['path']) . $m['trail'] . $m['close'],
                $part,
            );
        }

        return implode('', $parts);
    }
}
