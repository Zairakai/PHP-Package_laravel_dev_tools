<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Services\ConfigStubPublisher;
use Zairakai\LaravelDevTools\Services\TextPublishOutput;

/*
 * Publishes a group or a key of the configuration in a project, with no Laravel: the entry of setup-package.sh.
 *
 * Usage: php publish.php <project root> <vendor path of this package> <group or key> [--force] [--fullstack]
 *
 * The work is done by ConfigStubPublisher, the same code as `php artisan dev-tools:publish`.
 */

$root   = $argv[1] ?? getcwd();
$vendor = $argv[2] ?? dirname(__DIR__);
$target = $argv[3] ?? 'all';

require __DIR__ . '/bootstrap-package.php';

$published = (new ConfigStubPublisher(new TextPublishOutput()))->publishTarget(
    $target,
    $vendor,
    $root,
    in_array('--force', $argv, true),
    in_array('--fullstack', $argv, true),
);

exit($published ? 0 : 1);
