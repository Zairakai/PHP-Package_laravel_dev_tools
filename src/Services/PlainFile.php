<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Deletes a file with PHP alone.
 *
 * The publication runs in projects without Laravel, where the `File` facade does not exist. Rector (Laravel set) turns
 * every `unlink()` into `File::delete()`: this class is the one place that it is told to leave alone (rector.php).
 */
final class PlainFile
{
    public static function delete(string $file): void
    {
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Deletes a folder and everything in it.
     */
    public static function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            $entry->isDir()
                ? rmdir($entry->getPathname())
                : self::delete($entry->getPathname());
        }

        rmdir($directory);
    }
}
