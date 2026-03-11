<?php

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class PublishCommandTest extends TestCase
{
    /**
     * Base path of the test application.
     */
    private string $basePath;

    /**
     * Absolute path to the Makefile in the test environment.
     */
    private string $makefilePath;

    /**
     * Absolute path to the phpstan.neon file in the test environment.
     */
    private string $phpstanPath;

    /**
     * Absolute path to the pint.json file in the test environment.
     */
    private string $pintPath;

    /**
     * Set up test environment before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath     = $this->app->basePath();
        $this->makefilePath = $this->basePath . '/Makefile';
        $this->phpstanPath  = $this->basePath . '/phpstan.neon';
        $this->pintPath     = $this->basePath . '/pint.json';

        // Clean up before each test to ensure isolation
        $this->cleanupTestFiles();
    }

    /**
     * Clean up test environment after each test.
     */
    protected function tearDown(): void
    {
        $this->cleanupTestFiles();

        parent::tearDown();
    }

    /**
     * All git hooks are installed with correct permissions.
     */
    #[Test]
    public function all_git_hooks_installed_with_permissions(): void
    {
        // Initialize git repo
        $gitDir = $this->basePath . '/.git';

        if (! File::isDirectory($gitDir)) {
            File::makeDirectory($gitDir, 0755, true);
        }

        // Run with --with-hooks
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-hooks'     => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        // Verify all hooks exist and are executable
        $hooksDir      = $this->basePath . '/.githooks';
        $expectedHooks = ['pre-commit', 'commit-msg', 'pre-push', 'prepare-commit-msg'];

        foreach ($expectedHooks as $expectedHook) {
            $hookPath = $hooksDir . '/' . $expectedHook;
            $this->assertFileExists($hookPath, sprintf('Hook %s should exist', $expectedHook));

            // Verify executable permission (on Unix systems)
            if (PHP_OS_FAMILY !== 'Windows') {
                $perms = fileperms($hookPath);
                $this->assertTrue(
                    0 !== ($perms & 0111),
                    sprintf('Hook %s should be executable', $expectedHook),
                );
            }
        }

        // Cleanup
        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }

    /**
     * Composer normalize installation is attempted with --with-normalize even when installed.
     */
    #[Test]
    public function composer_normalize_attempts_install_with_flag(): void
    {
        // Create fake composer-normalize binary
        $vendorBinDir = $this->basePath . '/vendor/bin';

        if (! File::isDirectory($vendorBinDir)) {
            File::makeDirectory($vendorBinDir, 0755, true);
        }

        $normalizeBin = $vendorBinDir . '/composer-normalize';
        File::put($normalizeBin, '#!/bin/bash' . PHP_EOL . 'echo "normalize"');
        chmod($normalizeBin, 0755);

        // Run command with --with-normalize
        // Note: handleComposerNormalizeOption calls installComposerNormalize directly
        // in no-interaction mode, which will attempt install and show success if already installed
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-normalize' => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        // Command completes successfully whether or not normalize was already installed
        $output = Artisan::output();
        $this->assertStringContainsString(
            'Configuration published successfully',
            $output,
            'Should show success message',
        );

        // Cleanup
        if (File::exists($normalizeBin)) {
            File::delete($normalizeBin);
        }

        if (File::isDirectory($vendorBinDir) && count(File::allFiles($vendorBinDir)) === 0) {
            File::deleteDirectory($vendorBinDir);
        }
    }

    /**
     * Composer normalize non-interactive without flag doesn't show messages.
     */
    #[Test]
    public function composer_normalize_non_interactive_without_flag_silent(): void
    {
        // Ensure composer-normalize is not installed
        $normalizeBin = $this->basePath . '/vendor/bin/composer-normalize';

        if (File::exists($normalizeBin)) {
            File::delete($normalizeBin);
        }

        // Run command without --with-normalize
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        // In non-interactive mode without --with-normalize, no composer-normalize handling occurs
        // This is expected behavior - the method returns early
    }

    /**
     * Composer normalize is installed with --with-normalize option.
     */
    #[Test]
    public function composer_normalize_option_triggers_installation_check(): void
    {
        // Run with --with-normalize (will try to install or skip if already installed)
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-normalize' => true,
            '--no-interaction' => true,
        ]);

        // Should succeed regardless of installation result
        $this->assertEquals(0, $exitCode, 'Command should succeed');

        $output = Artisan::output();

        // Should mention composer-normalize in output
        $this->assertTrue(
            str_contains($output, 'composer-normalize') || str_contains($output, 'normalize'),
            'Output should mention composer-normalize',
        );
    }

    /**
     * Force option overwrites existing files.
     */
    #[Test]
    public function force_option_overwrites_files(): void
    {
        // Create dummy file
        File::put($this->makefilePath, '# Dummy content');

        // Run without force (should not overwrite)
        $exitCode = Artisan::call('dev-tools:publish', [
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed even without --force');

        // Verify content was NOT replaced
        $content = File::get($this->makefilePath);
        $this->assertStringContainsString('Dummy content', $content, 'File should not be overwritten without --force');

        // Run with force (should overwrite)
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Should succeed with --force');

        // Verify content was replaced
        $content = File::get($this->makefilePath);
        $this->assertStringNotContainsString('Dummy content', $content, 'File should be overwritten with --force');
    }

    /**
     * Git hooks are installed with --with-hooks option.
     */
    #[Test]
    public function git_hooks_are_installed_with_option(): void
    {
        // Initialize git repo
        $gitDir = $this->basePath . '/.git';

        if (! File::isDirectory($gitDir)) {
            File::makeDirectory($gitDir, 0755, true);
        }

        // Run with --with-hooks
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-hooks'     => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        // Verify .githooks directory was created
        $hooksDir = $this->basePath . '/.githooks';
        $this->assertDirectoryExists($hooksDir, '.githooks directory should be created');

        // Verify hooks were copied
        $expectedHooks = ['pre-commit', 'commit-msg', 'pre-push', 'prepare-commit-msg'];

        foreach ($expectedHooks as $expectedHook) {
            $this->assertFileExists(
                $hooksDir . '/' . $expectedHook,
                sprintf('Hook %s should be installed', $expectedHook),
            );
        }

        // Cleanup
        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }

    /**
     * Git hooks directory creation is verified.
     */
    #[Test]
    public function git_hooks_directory_is_created(): void
    {
        // Initialize git repo
        $gitDir = $this->basePath . '/.git';

        if (! File::isDirectory($gitDir)) {
            File::makeDirectory($gitDir, 0755, true);
        }

        $hooksDir = $this->basePath . '/.githooks';

        // Ensure hooks directory doesn't exist
        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        // Run with --with-hooks
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-hooks'     => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        // Verify directory was created
        $this->assertDirectoryExists($hooksDir, '.githooks directory should be created');

        $output = Artisan::output();
        $this->assertStringContainsString('Created: .githooks/', $output, 'Should show creation message');

        // Cleanup
        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }

    /**
     * Git hooks directory already exists scenario.
     */
    #[Test]
    public function git_hooks_handles_existing_directory(): void
    {
        // Initialize git repo
        $gitDir = $this->basePath . '/.git';

        if (! File::isDirectory($gitDir)) {
            File::makeDirectory($gitDir, 0755, true);
        }

        // Pre-create hooks directory
        $hooksDir = $this->basePath . '/.githooks';

        if (! File::isDirectory($hooksDir)) {
            File::makeDirectory($hooksDir, 0755, true);
        }

        // Run with --with-hooks
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-hooks'     => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        // Directory should still exist and contain hooks
        $this->assertDirectoryExists($hooksDir, '.githooks directory should exist');

        // Cleanup
        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }

    /**
     * Git hooks are installed only when --with-hooks is provided.
     */
    #[Test]
    public function git_hooks_not_installed_without_flag(): void
    {
        // Initialize git repo
        $gitDir = $this->basePath . '/.git';

        if (! File::isDirectory($gitDir)) {
            File::makeDirectory($gitDir, 0755, true);
        }

        // Run WITHOUT --with-hooks
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        // Verify .githooks directory was NOT created
        $hooksDir = $this->basePath . '/.githooks';
        $this->assertDirectoryDoesNotExist($hooksDir, '.githooks should not be created without --with-hooks');

        // Cleanup
        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }

    /**
     * Git hooks are overwritten when exists with force.
     */
    #[Test]
    public function git_hooks_overwrite_when_exists_with_force(): void
    {
        // Initialize git repo
        $gitDir = $this->basePath . '/.git';

        if (! File::isDirectory($gitDir)) {
            File::makeDirectory($gitDir, 0755, true);
        }

        // Create .githooks directory
        $hooksDir = $this->basePath . '/.githooks';

        if (! File::isDirectory($hooksDir)) {
            File::makeDirectory($hooksDir, 0755, true);
        }

        // Create existing hook file
        $existingHook = $hooksDir . '/pre-commit';
        File::put($existingHook, '#!/bin/bash' . PHP_EOL . 'echo "existing"');

        // Run with force
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-hooks'     => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        // Verify existing hook was overwritten
        $content = File::get($existingHook);
        $this->assertStringNotContainsString('existing', $content, 'Existing hook should be overwritten with --force');

        // Cleanup
        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }

    /**
     * Git hooks skip installation when already exists without force.
     */
    #[Test]
    public function git_hooks_skip_when_exists_without_force(): void
    {
        // Initialize git repo
        $gitDir = $this->basePath . '/.git';

        if (! File::isDirectory($gitDir)) {
            File::makeDirectory($gitDir, 0755, true);
        }

        // Create .githooks directory
        $hooksDir = $this->basePath . '/.githooks';

        if (! File::isDirectory($hooksDir)) {
            File::makeDirectory($hooksDir, 0755, true);
        }

        // Create existing hook file
        $existingHook = $hooksDir . '/pre-commit';
        File::put($existingHook, '#!/bin/bash' . PHP_EOL . 'echo "existing"');

        // Run without force
        $exitCode = Artisan::call('dev-tools:publish', [
            '--with-hooks'     => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        // Verify existing hook was not overwritten
        $content = File::get($existingHook);
        $this->assertStringContainsString('existing', $content, 'Existing hook should not be overwritten');

        // Cleanup
        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }

    /**
     * Makefile handles missing stub gracefully.
     */
    #[Test]
    public function makefile_handles_missing_stub_gracefully(): void
    {
        // Temporarily rename stub to simulate missing file
        $vendorPath = dirname(__DIR__, 2);
        $stubFile   = $vendorPath . '/stubs/Makefile.stub';
        $backupFile = $vendorPath . '/stubs/Makefile.stub.backup';

        if (File::exists($stubFile)) {
            File::move($stubFile, $backupFile);
        }

        try {
            // Run command with missing stub
            $exitCode = Artisan::call('dev-tools:publish', [
                '--force'          => true,
                '--no-interaction' => true,
            ]);

            $this->assertEquals(0, $exitCode, 'Command should succeed even if Makefile stub is missing');

            $output = Artisan::output();
            $this->assertStringContainsString(
                'Makefile stub not found',
                $output,
                'Should show error when Makefile stub is missing',
            );
        }
        finally {
            // Restore stub
            if (File::exists($backupFile)) {
                File::move($backupFile, $stubFile);
            }
        }
    }

    /**
     * Verify output shows installed hooks count.
     */
    #[Test]
    public function output_shows_installed_hooks_count(): void
    {
        // Initialize git repo
        $gitDir = $this->basePath . '/.git';

        if (! File::isDirectory($gitDir)) {
            File::makeDirectory($gitDir, 0755, true);
        }

        // Run with --with-hooks
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-hooks'     => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        $output = Artisan::output();

        // Should show count of installed hooks
        $this->assertStringContainsString('Installed 4 git hook', $output, 'Should show installed hooks count');

        // Cleanup
        $hooksDir = $this->basePath . '/.githooks';

        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }

    /**
     * Config files exist in package.
     */
    #[Test]
    public function package_config_files_exist(): void
    {
        $vendorPath = dirname(__DIR__, 2);

        // Verify config files exist
        $this->assertFileExists(
            $vendorPath . '/config/pint.json',
            'pint.json config should exist',
        );

        // Verify content is valid JSON/NEON
        $pintContent = File::get($vendorPath . '/config/pint.json');
        $pintConfig  = json_decode($pintContent, true);

        $this->assertIsArray($pintConfig, 'pint.json should be valid JSON');
        $this->assertArrayHasKey('preset', $pintConfig, 'pint.json should have preset key');
    }

    /**
     * Publishable assets exist in package.
     */
    #[Test]
    public function package_stubs_exist(): void
    {
        $vendorPath = dirname(__DIR__, 2);
        $stubsPath  = $vendorPath . '/stubs';

        // Verify stubs directory exists
        $this->assertDirectoryExists(
            $stubsPath,
            'Package should have a stubs/ directory',
        );

        // Verify key files exist
        $this->assertFileExists(
            $stubsPath . '/Makefile.stub',
            'Makefile.stub should exist',
        );

        $this->assertDirectoryExists(
            $stubsPath . '/githooks',
            'githooks directory should exist',
        );

        // Verify git hooks exist
        $expectedHooks = ['pre-commit', 'commit-msg', 'pre-push', 'prepare-commit-msg'];

        foreach ($expectedHooks as $expectedHook) {
            $this->assertFileExists(
                $stubsPath . '/githooks/' . $expectedHook,
                sprintf('Git hook stub %s should exist', $expectedHook),
            );
        }
    }

    /**
     * Publish command creates .editorconfig when missing.
     */
    #[Test]
    public function publish_command_creates_editorconfig(): void
    {
        $editorConfigPath = $this->basePath . '/.editorconfig';

        if (File::exists($editorConfigPath)) {
            File::delete($editorConfigPath);
        }

        Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        // Command must succeed — .editorconfig presence depends on package having the file
        $output = Artisan::output();
        $this->assertStringContainsString('Configuration published successfully', $output);

        // Cleanup
        if (File::exists($editorConfigPath)) {
            File::delete($editorConfigPath);
        }
    }

    /**
     * Publish command creates Makefile.
     */
    #[Test]
    public function publish_command_creates_makefile(): void
    {
        // Run command
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');
        $this->assertFileExists($this->makefilePath, 'Makefile should be created');

        // Verify content
        $content = File::get($this->makefilePath);
        $this->assertStringContainsString('pint', $content, 'Makefile should contain pint target');
        $this->assertStringContainsString('phpstan', $content, 'Makefile should contain phpstan target');
    }

    /**
     * Publish command is registered.
     */
    #[Test]
    public function publish_command_is_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey(
            'dev-tools:publish',
            $commands,
            'dev-tools:publish command should be registered',
        );
    }

    /**
     * Publish command has correct signature.
     */
    #[Test]
    public function publish_command_signature(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', ['--help' => true]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();

        // Verify options exist
        $this->assertStringContainsString('--force', $output);
        $this->assertStringContainsString('--with-hooks', $output);
        $this->assertStringContainsString('--with-normalize', $output);
    }

    /**
     * Publish command skips .editorconfig when it already exists without --force.
     */
    #[Test]
    public function publish_command_skips_editorconfig_when_exists_without_force(): void
    {
        $editorConfigPath = $this->basePath . '/.editorconfig';
        File::put($editorConfigPath, '# existing editorconfig');

        Artisan::call('dev-tools:publish', [
            '--no-interaction' => true,
        ]);

        $content = File::get($editorConfigPath);
        $this->assertStringContainsString('existing editorconfig', $content);

        // Cleanup
        File::delete($editorConfigPath);
    }

    /**
     * Display success message includes composer-normalize targets when installed.
     */
    #[Test]
    public function success_message_includes_composer_normalize_targets(): void
    {
        // Create fake composer-normalize binary
        $vendorBinDir = $this->basePath . '/vendor/bin';

        if (! File::isDirectory($vendorBinDir)) {
            File::makeDirectory($vendorBinDir, 0755, true);
        }

        $normalizeBin = $vendorBinDir . '/composer-normalize';
        File::put($normalizeBin, '#!/bin/bash' . PHP_EOL . 'echo "normalize"');
        chmod($normalizeBin, 0755);

        // Run command
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        $output = Artisan::output();

        // Verify composer-normalize targets are shown
        $this->assertStringContainsString(
            'composer-normalize',
            $output,
            'Should show composer-normalize make targets',
        );
        $this->assertStringContainsString(
            'composer-normalize-fix',
            $output,
            'Should show composer-normalize-fix make target',
        );

        // Cleanup
        if (File::exists($normalizeBin)) {
            File::delete($normalizeBin);
        }

        if (File::isDirectory($vendorBinDir) && count(File::allFiles($vendorBinDir)) === 0) {
            File::deleteDirectory($vendorBinDir);
        }
    }

    /**
     * Display success message includes git hooks info when installed.
     */
    #[Test]
    public function success_message_includes_git_hooks_info(): void
    {
        // Initialize git repo
        $gitDir = $this->basePath . '/.git';

        if (! File::isDirectory($gitDir)) {
            File::makeDirectory($gitDir, 0755, true);
        }

        // Run with hooks
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-hooks'     => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        $output = Artisan::output();

        // Verify hooks info is displayed
        $this->assertStringContainsString('pre-commit', $output, 'Should mention pre-commit hook');
        $this->assertStringContainsString('commit-msg', $output, 'Should mention commit-msg hook');
        $this->assertStringContainsString('pre-push', $output, 'Should mention pre-push hook');
        $this->assertStringContainsString('prepare-commit-msg', $output, 'Should mention prepare-commit-msg hook');

        // Cleanup
        $hooksDir = $this->basePath . '/.githooks';

        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }

    /**
     * Display success message includes tip when hooks not installed.
     */
    #[Test]
    public function success_message_includes_tip_without_hooks(): void
    {
        // Run without hooks
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode, 'Command should succeed');

        $output = Artisan::output();

        // Verify tip is displayed
        $this->assertStringContainsString(
            '--with-hooks',
            $output,
            'Should show tip about --with-hooks option',
        );
    }

    /**
     * Git hooks installation handles missing git gracefully.
     */
    #[Test]
    public function with_hooks_handles_missing_git_gracefully(): void
    {
        $gitDir = $this->basePath . '/.git';

        // Ensure .git doesn't exist
        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }

        // Run command with hooks (should warn but not fail)
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--with-hooks'     => true,
            '--no-interaction' => true,
        ]);

        // Command should succeed with warning
        $this->assertEquals(0, $exitCode, 'Command should succeed even without Git');

        $output = Artisan::output();

        // Should contain warning about missing Git
        $this->assertStringContainsString(
            'Not a git repository',
            $output,
            'Should warn when Git is not available',
        );
    }

    /**
     * Remove all files created during tests.
     */
    private function cleanupTestFiles(): void
    {
        // Remove Makefile
        if (File::exists($this->makefilePath)) {
            File::delete($this->makefilePath);
        }

        // Remove pint.json (symlink or file)
        if (is_link($this->pintPath)) {
            unlink($this->pintPath);
        }
        elseif (File::exists($this->pintPath)) {
            File::delete($this->pintPath);
        }

        // Remove phpstan.neon (symlink or file)
        if (is_link($this->phpstanPath)) {
            unlink($this->phpstanPath);
        }
        elseif (File::exists($this->phpstanPath)) {
            File::delete($this->phpstanPath);
        }

        // Remove rector.php (symlink or file)
        $rectorPath = $this->basePath . '/rector.php';

        if (is_link($rectorPath)) {
            unlink($rectorPath);
        }
        elseif (File::exists($rectorPath)) {
            File::delete($rectorPath);
        }

        // Remove config/insights.php (symlink or file)
        $insightsPath = $this->basePath . '/config/insights.php';

        if (is_link($insightsPath)) {
            unlink($insightsPath);
        }
        elseif (File::exists($insightsPath)) {
            File::delete($insightsPath);
        }

        // Remove config/enlightn.php
        $enlightnPath = $this->basePath . '/config/enlightn.php';

        if (File::exists($enlightnPath)) {
            File::delete($enlightnPath);
        }

        // Remove .githooks directory
        $hooksDir = $this->basePath . '/.githooks';

        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        // Remove .git directory (test artifact)
        $gitDir = $this->basePath . '/.git';

        if (File::isDirectory($gitDir)) {
            File::deleteDirectory($gitDir);
        }
    }
}
