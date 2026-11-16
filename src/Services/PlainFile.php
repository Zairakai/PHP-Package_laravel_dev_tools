<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

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
}
