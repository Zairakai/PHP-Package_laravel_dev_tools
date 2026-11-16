<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Services\SetupChecker;

/*
 * Tells what the setup would publish that the project does not have, or has differently. Writes nothing.
 *
 * Usage: php setup-check.php <project root> <vendor path of this package>
 *
 * Exit code: 0 when every file is the same, 1 when a file is missing or different.
 */

$root   = $argv[1] ?? getcwd();
$vendor = $argv[2] ?? dirname(__DIR__);

require __DIR__ . '/bootstrap-package.php';

$problems = 0;

foreach ((new SetupChecker())->check($vendor, $root) as $result) {
    if (SetupChecker::SAME === $result['state']) {
        continue;
    }

    $problems++;

    echo $result['file'] . ': ' . $result['state'] . ('' === $result['detail'] ? '' : ' (' . $result['detail'] . ')') . "\n";
}

exit(0 === $problems ? 0 : 1);
