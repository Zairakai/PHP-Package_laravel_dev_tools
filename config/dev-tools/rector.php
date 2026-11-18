<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Rector\RectorBaseConfig;

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

// Only what is specific to the project: the base configuration is in vendor/zairakai/laravel-dev-tools.
return RectorBaseConfig::configure(
    projectRoot: $root,
    extraPaths: [],
    extraSkips: [
        // Deletes a file with PHP alone, for the projects without Laravel (the Laravel set would use the File facade)
        $root . '/src/Services/PlainFile.php',
    ],
);
