<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

/**
 * The files of the root that an IDE or an extension reads, and their link with config/dev-tools/.
 *
 * A project configures its tools in config/dev-tools/, one file per setting. The root holds a thin file that points
 * there (.markdownlint.json); a file that no editor reads stays in config/dev-tools/ only (phpstan.neon, phpunit.xml,
 * pint.json, rector.php); a file that the tool reads from the root only stays there only (.markdownlintignore). No file
 * is copied: a second file would be a second place to keep in step. This class tells what does not follow that.
 *
 * PHPUnit reads the paths of its file from the folder of that file: the one of config/dev-tools/ goes up two folders
 * (`../../src`), the stub of the package does not (`src`).
 */
final class ConfigCopies
{
    /**
     * The files that no editor reads: the configuration is in config/dev-tools/ only, and a file of the root would say
     * something else than the commands read.
     */
    public const array NOT_AT_THE_ROOT = [
        'rector.php',
        'phpinsights.php',
        'phpunit.xml',
        'pint.json',
        'phpstan.neon',
    ];

    /**
     * The files that the tool reads from the root only (an ignore file cannot extend another one, and no setting of an
     * editor points elsewhere): a file of config/dev-tools/ would say something else than the commands read.
     */
    public const array ONLY_AT_THE_ROOT = [
        '.markdownlintignore',
    ];

    public const string PUBLISHED = 'config/dev-tools/';

    /**
     * The thin files of the root, and what each must point at.
     */
    public const array THIN_FILES = [
        '.markdownlint.json' => './config/dev-tools/.markdownlint.json',
    ];

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
}
