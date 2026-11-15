<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Services\PintConfigWriter;

/*
 * Writes the pint.json of a project, and prints its path.
 *
 * Usage: php pint-config.php <project root> <file to write, from the root> [<pint.json layer>...]
 *
 * Pint cannot extend a file: the pint.json of config/dev-tools/ is generated from the rules of
 * stanislas-poisson/php-dev-tools and from the layers given, in order (the Laravel layer of this package). The work is
 * done by PintConfigWriter.
 */

$root = $argv[1] ?? getcwd();

require $root . '/vendor/autoload.php';

try {
    echo PintConfigWriter::write($root, array_slice($argv, 3), $argv[2] ?? 'config/dev-tools/pint.json');
}
catch (RuntimeException $runtimeException) {
    fwrite(STDERR, 'pint-config: ' . $runtimeException->getMessage() . "\n");

    exit(1);
}
