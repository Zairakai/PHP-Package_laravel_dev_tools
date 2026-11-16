<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use RuntimeException;
use Zairakai\LaravelDevTools\Update\Json;

/**
 * Reads and writes the composer.json of a project, and the scripts in it, with the indentation that the file has.
 */
final class ComposerJson
{
    /**
     * json_encode indents with 4 spaces: the output is indented as the file was (2 spaces when it cannot be told).
     *
     * @param array<string, mixed> $composer
     */
    public static function encode(array $composer, string $original): string
    {
        $indent = 1 === preg_match('/^([ \t]+)"/m', $original, $match)
            ? $match[1]
            : '  ';
        $json   = (string) json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return preg_replace_callback(
            '/^( +)/m',
            static fn (array $found): string => str_repeat($indent, intdiv(strlen($found[1]), 4)),
            $json,
        ) . "\n";
    }

    /**
     * @return array{string, array<string, mixed>} the text of the file, and what it decodes to
     *
     * @throws RuntimeException when the file is missing or cannot be read
     */
    public static function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('%s not found.', basename($path)));
        }

        $text = (string) file_get_contents($path);
        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new RuntimeException(sprintf('Failed to parse %s.', basename($path)));
        }

        return [$text, Json::map($data)];
    }

    /**
     * @param array<string, mixed> $composer
     *
     * @return array<string, mixed>
     */
    public static function scripts(array $composer): array
    {
        return Json::map($composer['scripts'] ?? []);
    }
}
