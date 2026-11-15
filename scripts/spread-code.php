<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Services\CodeSpreader;

/*
 * Spreads the arrays of more than two elements and the calls of more than two arguments, on several lines.
 *
 * Usage: php spread-code.php <project root> <directory>...
 *
 * The work is done by CodeSpreader. The files of tests/Fixtures are left as they are. Prints the number of files
 * rewritten.
 */

$root = $argv[1] ?? getcwd();

require $root . '/vendor/autoload.php';

$spreader = new CodeSpreader();
$changed  = 0;

foreach (array_slice($argv, 2) as $directory) {
    if (! is_dir($directory)) {
        continue;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if ('php' !== $file->getExtension() || str_contains($path, '/Fixtures/')) {
            continue;
        }

        $source = (string) file_get_contents($path);
        $spread = $spreader->spread($source);

        if ($spread !== $source) {
            file_put_contents($path, $spread);
            $changed++;
        }
    }
}

echo $changed . "\n";
