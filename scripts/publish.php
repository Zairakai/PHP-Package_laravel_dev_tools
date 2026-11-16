<?php

declare(strict_types=1);

use StanislasPoisson\DevTools\Pint;
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

// The code of this package is loaded from its own folder.
spl_autoload_register(static function (string $class) use ($vendor): void {
    $prefix = 'Zairakai\\LaravelDevTools\\';

    if (str_starts_with($class, $prefix)) {
        $file = $vendor . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

// The autoload of the project gives the rules of php-dev-tools. When it does not have them, the autoload of the package
// itself (developed on its own) does.
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

if (! class_exists(Pint::class) && is_file($vendor . '/vendor/autoload.php')) {
    require $vendor . '/vendor/autoload.php';
}

$published = (new ConfigStubPublisher(new TextPublishOutput()))->publishTarget(
    $target,
    $vendor,
    $root,
    in_array('--force', $argv, true),
    in_array('--fullstack', $argv, true),
);

exit($published ? 0 : 1);
