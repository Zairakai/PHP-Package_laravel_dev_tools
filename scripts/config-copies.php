<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Services\ConfigCopies;

/*
 * Checks, or writes again, the files of the root that an IDE reads, against config/dev-tools/.
 *
 * Usage: php config-copies.php check <project root>
 *        php config-copies.php phpunit-config <phpunit.xml of the package> <file to write>
 *
 * check prints one line per problem and exits with 1 when there is one. phpunit-config writes the phpunit.xml of
 * config/dev-tools/ from the one of the package, whose paths are the ones of the root.
 */

// The classes have no dependency: they do not need the autoloader of the project
require dirname(__DIR__) . '/src/Services/PhpunitPaths.php';

require dirname(__DIR__) . '/src/Services/ConfigCopiesCheck.php';

require dirname(__DIR__) . '/src/Services/ConfigCopies.php';

$action = $argv[1] ?? '';

if ('phpunit-config' === $action) {
    file_put_contents($argv[3] ?? '', ConfigCopies::phpunitForConfig((string) file_get_contents($argv[2] ?? '')));

    exit(0);
}

$root = $argv[2] ?? (string) getcwd();

if ('check' !== $action) {
    fwrite(STDERR, "Usage: php config-copies.php <check|phpunit-config> <project root>\n");

    exit(2);
}

$problems = ConfigCopies::problems($root);

foreach ($problems as $problem) {
    echo $problem . "\n";
}

exit([] === $problems ? 0 : 1);
