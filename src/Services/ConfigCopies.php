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

    public const string PUBLISHED = 'config/dev-tools/';

    /**
     * The thin files of the root, and what each must point at.
     */
    public const array THIN_FILES = [
        'phpstan.neon'       => 'config/dev-tools/phpstan.neon',
        '.markdownlint.json' => './config/dev-tools/.markdownlint.json',
    ];

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
        return PhpunitPaths::convert(
            $xml,
            static fn (string $path): string => '../../' . $path,
        );
    }

    /**
     * The phpunit.xml of the root from the one of config/dev-tools/.
     */
    public static function phpunitForTheRoot(string $xml): string
    {
        return PhpunitPaths::convert(
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
        return ConfigCopiesCheck::problems($root);
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
        $wanted = [] === $names
            ? self::COPIES
            : array_values(array_intersect(self::COPIES, $names));

        return array_values(array_filter(
            $wanted,
            static fn (string $name): bool => self::write($root, $name, $before),
        ));
    }

    /**
     * Writes one copy from the file of config/dev-tools/, when that file exists.
     *
     * @param callable(string): mixed|null $before called with the path of a copy that exists, before it is written over
     */
    private static function write(string $root, string $name, ?callable $before): bool
    {
        $published = $root . '/' . self::PUBLISHED . $name;

        if (! is_file($published)) {
            return false;
        }

        $content = self::expectedCopy($name, (string) file_get_contents($published));
        $copy    = $root . '/' . $name;

        if (
            null !== $before
            && is_file($copy)
            && (string) file_get_contents($copy) !== $content
        ) {
            $before($copy);
        }

        file_put_contents($copy, $content);

        return true;
    }
}
