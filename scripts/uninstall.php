<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Services\SetupRemover;

/*
 * Removes the files of the setup that are still what the package publishes. Prints what it removes and what it keeps.
 *
 * Usage: php uninstall.php <project root> <vendor path of this package> [--dry-run] [--customized]
 *
 *   --dry-run     only lists the files
 *   --customized  also removes the files of config/dev-tools/ that the project changed
 *
 * Everything that is removed is copied first in .dev-tools-backup/<date>/, under its path from the root.
 */

$root   = $argv[1] ?? getcwd();
$vendor = $argv[2] ?? dirname(__DIR__);

require __DIR__ . '/bootstrap-package.php';

$done = new SetupRemover()->remove($vendor, $root, in_array('--dry-run', $argv, true), in_array('--customized', $argv, true));

foreach ($done['removed'] as $file) {
    echo 'remove: ' . $file . "\n";
}

foreach ($done['kept'] as $item) {
    echo 'keep: ' . $item['file'] . ' (' . $item['reason'] . ")\n";
}

if (null !== $done['backup']) {
    echo 'backup: ' . $done['backup'] . "\n";
}
