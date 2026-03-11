<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\TestCase;

/**
 * Tests for composer-normalize handling in PublishToolingCommand.
 *
 * These tests cover the intelligent decision logic for composer-normalize installation,
 * including interactive/non-interactive modes and various flag combinations.
 *
 * Note: Actual composer installation is not tested (requires integration tests).
 * We focus on testing the command's decision-making logic.
 */
final class PublishToolingComposerNormalizeTest extends TestCase
{
    #[Test]
    public function detects_composer_normalize_already_installed(): void
    {
        $basePath = $this->app->basePath();

        // Simulate composer-normalize already installed
        $vendorBinPath = $basePath . '/vendor/bin';

        if (! File::isDirectory($vendorBinPath)) {
            File::makeDirectory($vendorBinPath, 0755, true);
        }

        File::put($vendorBinPath . '/composer-normalize', '#!/usr/bin/env php');

        // Run publish command in INTERACTIVE mode (handleComposerNormalize is only called without --no-interaction)
        $this->artisan('dev-tools:publish')
            ->expectsOutputToContain('composer-normalize is already installed')
            ->assertExitCode(0);

        // Cleanup
        File::delete($vendorBinPath . '/composer-normalize');
    }

    #[Test]
    public function interactive_mode_accepts_installation_prompt(): void
    {
        $basePath = $this->app->basePath();

        // Ensure composer-normalize is NOT installed
        $this->ensureComposerNormalizeNotInstalled($basePath);

        // Run in interactive mode and accept the prompt
        $this->artisan('dev-tools:publish')
            ->expectsConfirmation('Install ergebnis/composer-normalize now?', 'yes')
            ->expectsOutputToContain('Installing ergebnis/composer-normalize')
            ->assertExitCode(0);
    }

    #[Test]
    public function interactive_mode_declines_installation_prompt(): void
    {
        $basePath = $this->app->basePath();

        // Ensure composer-normalize is NOT installed
        $this->ensureComposerNormalizeNotInstalled($basePath);

        // Run in interactive mode and decline the prompt
        $this->artisan('dev-tools:publish')
            ->expectsConfirmation('Install ergebnis/composer-normalize now?', 'no')
            ->expectsOutputToContain('Skipped. Install later with:')
            ->expectsOutputToContain('composer require --dev ergebnis/composer-normalize')
            ->assertExitCode(0);
    }

    #[Test]
    public function non_interactive_mode_with_normalize_flag_triggers_installation(): void
    {
        $basePath = $this->app->basePath();

        // Ensure composer-normalize is NOT installed
        $this->ensureComposerNormalizeNotInstalled($basePath);

        // Run with --no-interaction --with-normalize
        // Note: This will attempt actual composer installation in CI, which might fail
        // We're testing the decision logic, not the installation itself
        $this->artisan('dev-tools:publish --no-interaction --with-normalize')
            ->expectsOutputToContain('Installing ergebnis/composer-normalize')
            ->assertExitCode(0);

        // The actual installation might fail (no composer.json, etc.)
        // but the command should have attempted it - that's what we test
    }

    #[Test]
    public function non_interactive_mode_without_normalize_flag_skips_installation(): void
    {
        $basePath = $this->app->basePath();

        // Ensure composer-normalize is NOT installed
        $this->ensureComposerNormalizeNotInstalled($basePath);

        // Run with --no-interaction but WITHOUT --with-normalize
        // handleComposerNormalizeOption does NOTHING in this case (no output)
        // We verify the command succeeds and doesn't attempt installation
        $this->artisan('dev-tools:publish --no-interaction')
            ->doesntExpectOutputToContain('Installing ergebnis/composer-normalize')
            ->doesntExpectOutputToContain('composer-normalize is not installed')
            ->assertExitCode(0);
    }

    /**
     * Ensure composer-normalize binary does not exist.
     */
    private function ensureComposerNormalizeNotInstalled(string $basePath): void
    {
        $normalizePath = $basePath . '/vendor/bin/composer-normalize';

        if (File::exists($normalizePath)) {
            File::delete($normalizePath);
        }
    }
}
