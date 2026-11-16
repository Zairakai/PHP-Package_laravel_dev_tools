#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Merge composer scripts from dev-tools stub into package composer.json.
 *
 * Usage: php merge-composer-scripts.php <project-root> <vendor-path> [--force]
 */
if (3 > $argc) {
    echo "Usage: php merge-composer-scripts.php <project-root> <vendor-path> [--force]\n";

    exit(1);
}

$projectRoot    = rtrim($argv[1], '/');
$vendorPath     = rtrim($argv[2], '/');
$forceOverwrite = in_array('--force', $argv, true);

/** Paths */
$composerJsonPath = "{$projectRoot}/composer.json";
$stubScriptsPath  = "{$vendorPath}/stubs/composer-scripts.json";

/* Validate files exist */
if (! file_exists($composerJsonPath)) {
    echo "Error: composer.json not found at {$composerJsonPath}\n";

    exit(1);
}

if (! file_exists($stubScriptsPath)) {
    echo "Error: composer-scripts.json stub not found at {$stubScriptsPath}\n";

    exit(1);
}

/** Read composer.json */
$originalContent = file_get_contents($composerJsonPath);
$composerJson    = json_decode($originalContent, true);

if (null === $composerJson) {
    echo "Error: Failed to parse composer.json\n";

    exit(1);
}

/** Read stub scripts */
$stubScripts = json_decode(
    file_get_contents($stubScriptsPath),
    true,
);

if (null === $stubScripts) {
    echo "Error: Failed to parse composer-scripts.json stub\n";

    exit(1);
}

/* Initialize scripts array if it doesn't exist */
$composerJson['scripts'] ??= [];

/** Merge scripts */
$mergedScripts  = $composerJson['scripts'];
$addedScripts   = [];
$skippedScripts = [];
$updatedScripts = [];

foreach ($stubScripts as $scriptName => $scriptValue) {
    if (! isset($mergedScripts[$scriptName])) {
        /* New script, add it */
        $mergedScripts[$scriptName] = $scriptValue;
        $addedScripts[]             = $scriptName;
    }
    elseif (
        $forceOverwrite
        && $mergedScripts[$scriptName] !== $scriptValue
    ) {
        /* Script exists, --force is used, update it */
        $mergedScripts[$scriptName] = $scriptValue;
        $updatedScripts[]           = $scriptName;
    }
    elseif ($mergedScripts[$scriptName] !== $scriptValue) {
        /* Script exists with different value, skip to preserve custom script */
        $skippedScripts[] = $scriptName;
    }
}

/* Update composer.json with merged scripts */
$composerJson['scripts'] = $mergedScripts;

/**
 * Write back to composer.json with the indentation it already has.
 * json_encode indents with 4 spaces, so the output is re-indented to the indent of the file (2 spaces by default).
 */
$indent = '  ';

if (1 === preg_match('/^([ \t]+)"/m', $originalContent, $indentMatch)) {
    $indent = $indentMatch[1];
}

$jsonContent = json_encode(
    $composerJson,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
);
$jsonContent = preg_replace_callback(
    '/^( +)/m',
    static fn (array $match): string => str_repeat($indent, intdiv(strlen($match[1]), 4)),
    $jsonContent,
) . "\n";

/* Leave the file alone when nothing changes */
if ($jsonContent !== $originalContent && false === file_put_contents($composerJsonPath, $jsonContent)) {
    echo "Error: Failed to write composer.json\n";

    exit(1);
}

/* Report results */
if ($addedScripts) {
    echo 'Added scripts: ' . implode(', ', $addedScripts) . "\n";
}

if ($updatedScripts) {
    echo 'Updated scripts (--force): ' . implode(', ', $updatedScripts) . "\n";
}

if ($skippedScripts) {
    echo 'Skipped scripts (already exist with custom values): ' . implode(', ', $skippedScripts) . "\n";
}

if (! $addedScripts && ! $updatedScripts) {
    echo "No scripts to add or update\n";
}

exit(0);
