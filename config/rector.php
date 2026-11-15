<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Rector\RectorBaseConfig;

// The default of a project that has no rector.php: this file is in vendor/zairakai/laravel-dev-tools/config, or in
// config/ when this package is developed.
$projectRoot = str_contains(__DIR__, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)
    ? dirname(__DIR__, 4)
    : dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';

return RectorBaseConfig::configure(projectRoot: $projectRoot);
