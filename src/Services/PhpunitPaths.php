<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

/**
 * The paths of a phpunit.xml, found by the names of the PHPUnit schema.
 *
 * - The attributes that hold a path: bootstrap, cacheDirectory, extensionsDirectory, outputDirectory, outputFile,
 *   baseline, testSuiteLoaderFile and xsi:noNamespaceSchemaLocation (the schema that the editor reads).
 * - The elements that hold a path as text: directory, file, includePath, and exclude when it holds text (an exclude
 *   with directory and file inside is left to them).
 *
 * A stream (`php://stdout`), an absolute path and a drive (`C:\`) are not paths of the project. A comment and a CDATA
 * section are left as they are, and so is the layout of the file: the DOM would write the root element on one line, and
 * the file is edited by the project.
 */
final class PhpunitPaths
{
    private const string ATTRIBUTES = '~(?<=\s)(?<name>(?:[A-Za-z_][\w.-]*:)?(?:bootstrap|cacheDirectory'
        . '|extensionsDirectory|outputDirectory|outputFile|baseline|testSuiteLoaderFile|noNamespaceSchemaLocation))'
        . '(?<equal>\s*=\s*)(?<quote>["\'])(?<value>[^"\']*)\k<quote>~';

    private const string ELEMENTS = '~(?<open><(?<name>directory|file|includePath|exclude)\b[^>]*>)(?<lead>\s*)'
        . '(?<path>[^<]*?)(?<trail>\s*)(?<close></\k<name>>)~';

    private const string NOT_A_PATH = '~^(?:[a-z][a-z0-9+.-]*://|/|[A-Za-z]:[\\\\/])~';

    /**
     * Applies $convert to every path of the file.
     *
     * @param callable(string): string $convert
     */
    public static function convert(string $xml, callable $convert): string
    {
        $path = static fn (string $value): string => 1 === preg_match(self::NOT_A_PATH, $value)
            ? $value
            : $convert($value);

        // The parts that are not comments or CDATA, which come back in the order of the file
        $parts = preg_split('~(<!--.*?-->|<!\[CDATA\[.*?\]\]>)~s', $xml, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (false === $parts) {
            return $xml;
        }

        foreach ($parts as $index => $part) {
            if (0 === $index % 2) {
                $parts[$index] = self::convertPart($part, $path);
            }
        }

        return implode('', $parts);
    }

    /**
     * @param callable(string): string $path
     */
    private static function convertPart(string $part, callable $path): string
    {
        // The attributes, in the start tags
        $part = (string) preg_replace_callback(
            self::ATTRIBUTES,
            static fn (array $m): string => $m['name'] . $m['equal'] . $m['quote']
                . ('' === trim($m['value']) ? $m['value'] : $path($m['value'])) . $m['quote'],
            $part,
        );

        // The elements with a path as text
        return (string) preg_replace_callback(
            self::ELEMENTS,
            static fn (array $m): string => '' === $m['path']
                ? $m[0]
                : $m['open'] . $m['lead'] . $path($m['path']) . $m['trail'] . $m['close'],
            $part,
        );
    }
}
