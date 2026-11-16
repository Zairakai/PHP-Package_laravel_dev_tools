<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

/**
 * The block of .gitignore for the files of the tools.
 */
final class GitignoreBlock
{
    public const string BLOCK = <<<'GITIGNORE'

        # Dev Tools temporary files
        # ----------------
        .*.tmp.*
        .dev-tools-backup/

        GITIGNORE;

    /**
     * The titles of the block: the current one, and the one of the older versions.
     */
    private const array TITLES = [
        '# Dev Tools temporary files',
        '# Dev Tools - Generated temporary files',
    ];

    /**
     * The text with the block added, or null when it already holds one (with this title or the older one).
     */
    public static function added(string $text): ?string
    {
        return self::present($text)
            ? null
            : $text . self::BLOCK;
    }

    /**
     * The text without the block, or null when it does not hold the block as the setup writes it.
     */
    public static function removed(string $text): ?string
    {
        return str_contains($text, self::BLOCK)
            ? str_replace(self::BLOCK, '', $text)
            : null;
    }

    private static function present(string $text): bool
    {
        return array_any(self::TITLES, static fn (string $title): bool => str_contains($text, $title));
    }
}
