<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Rector\RectorBaseConfig;

require __DIR__ . '/vendor/autoload.php';

// Only what is specific to the project: the base configuration is in vendor/zairakai/laravel-dev-tools.
return RectorBaseConfig::configure(
    projectRoot: __DIR__,
    extraPaths: [],
    extraSkips: [
        // Deletes a file with PHP alone, for the projects without Laravel (the Laravel set would use the File facade)
        __DIR__ . '/src/Services/PlainFile.php',
    ],
);
