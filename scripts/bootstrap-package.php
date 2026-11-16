<?php

declare(strict_types=1);

use StanislasPoisson\DevTools\Pint;

/*
 * Loads the code of this package for the scripts that run outside Artisan (publish.php, setup-check.php).
 *
 * The script that requires this file has $root (the project) and $vendor (the folder of this package).
 */

/** @var string $root */
/** @var string $vendor */

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
