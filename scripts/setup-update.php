<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Services\SetupUpdater;

/*
 * Publishes again the files that the setup reports as missing or different (see setup-check.php).
 *
 * Usage: php setup-update.php <project root> <vendor path of this package> [--dry-run]
 *
 * A file of the project that is replaced is copied in .dev-tools-backup/ first. Nothing is merged. A symbolic link is kept.
 */

$root   = $argv[1] ?? getcwd();
$vendor = $argv[2] ?? dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv, true);

require __DIR__ . '/bootstrap-package.php';

$done = new SetupUpdater()->update($vendor, $root, $dryRun);

foreach ($done['created'] as $file) {
    echo ($dryRun ? 'would create: ' : 'created: ') . $file . "\n";
}

foreach ($done['replaced'] as $file) {
    echo ($dryRun ? 'would replace: ' : 'replaced: ') . $file . "\n";
}

foreach ($done['kept'] as $file) {
    echo 'keep: ' . $file . " (symbolic link)\n";
}

if (null !== $done['backup']) {
    echo 'backup: ' . $done['backup'] . "\n";
}
