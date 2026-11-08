<?php

declare(strict_types=1);

use StanislasPoisson\DevTools\Pint;

/*
 * Writes the configuration of Pint of a project in build/pint.json, and prints its path.
 *
 * Usage: php pint-config.php <project root> [<pint.json>...]
 *
 * The rules come from stanislas-poisson/php-dev-tools. Each file given then extends them, in the order of the arguments (the
 * Laravel layer of this package, then the one of the project): a later file replaces the rules of an earlier one and adds
 * its exclusions.
 */

$root = $argv[1] ?? getcwd();

require $root . '/vendor/autoload.php';

/**
 * @return array<string, mixed>
 */
$read = static function (string $path): array {
    if (! is_file($path)) {
        fwrite(STDERR, "pint-config: {$path} not found\n");

        exit(1);
    }

    $config = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return is_array($config) ? $config : [];
};

$config = $read($root . '/vendor/stanislas-poisson/php-dev-tools/config/pint.json');

foreach (array_slice($argv, 2) as $layer) {
    $config = Pint::merge($config, $read($layer));
}

$directory = $root . '/build';

if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
    fwrite(STDERR, "pint-config: cannot create {$directory}\n");

    exit(1);
}

$file = $directory . '/pint.json';
file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

echo $file;
