<?php

declare(strict_types=1);
use Zairakai\LaravelDevTools\Rector\RectorBaseConfig;

/**
 * Rector Entry Configuration
 * ================
 * Responsibilities:
 * - Locate rector.base.php
 * - Load RectorBaseConfig
 * - Return configured RectorConfig instance.
 */
$fqcn        = RectorBaseConfig::class;
$currentFile = realpath(__FILE__) ?: __FILE__;
$baseConfig  = null;

// Detect whether running as a vendor dependency or in own package development.
// When used as vendor dep, __FILE__ contains /vendor/ in its path — project root
// is the directory sitting above the vendor/ segment.
// In own development, the file lives directly inside config/ → parent is the root.
$segments  = explode(DIRECTORY_SEPARATOR, $currentFile);
$vendorIdx = array_search('vendor', $segments, true);

if (false !== $vendorIdx) {
    $projectRoot = implode(DIRECTORY_SEPARATOR, array_slice($segments, 0, $vendorIdx));
}
else {
    $projectRoot = dirname(__DIR__);
}

/**
 * Locate base configuration
 * ================
 * Supported contexts:
 * - Installed dev-tool in project
 * - Testbench / package development
 * - Local project override.
 */
$possibleBaseConfigs = [
    $projectRoot . '/vendor/zairakai/laravel-dev-tools/config/rector.base.php',
    dirname($projectRoot) . '/vendor/zairakai/laravel-dev-tools/config/rector.base.php',
    $projectRoot . '/config/rector.base.php',
];

foreach ($possibleBaseConfigs as $path) {
    if (
        ! is_file($path)
        || realpath($path) === $currentFile
    ) {
        continue;
    }

    $baseConfig = realpath($path);

    break;
}

if (null === $baseConfig) {
    throw new RuntimeException('Unable to locate rector.base.php');
}

/**
 * Load base definition
 * ================
 * The file must define RectorBaseConfig class.
 */
require_once $baseConfig;

if (! class_exists($fqcn, false)) {
    throw new RuntimeException(
        sprintf('RectorBaseConfig not found in "%s"', $baseConfig),
    );
}

/*
 * Build configuration
 * ================
 * Project may later extend using extraPaths / extraSkips
 */
return $fqcn::configure(
    projectRoot: $projectRoot,
    extraPaths: [],
    extraSkips: [],
);
