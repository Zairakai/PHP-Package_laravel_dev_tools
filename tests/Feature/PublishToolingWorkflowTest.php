<?php

namespace Zairakai\LaravelDevTools\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class PublishToolingWorkflowTest extends TestCase
{
    /**
     * Complete publish workflow without git hooks.
     */
    #[Test]
    public function complete_publish_workflow_without_hooks(): void
    {
        $basePath = $this->app->basePath();

        // Clean workspace
        $this->cleanWorkspace($basePath);

        // Run publish command
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        // Should succeed
        $this->assertEquals(0, $exitCode, 'Publish command should succeed');

        // Verify all expected files are created
        $this->assertFileExists($basePath . '/Makefile', 'Makefile should be created');

        // Verify Makefile content
        $makefileContent = File::get($basePath . '/Makefile');

        $this->assertStringContainsString('help', $makefileContent);
        $this->assertStringContainsString('pint', $makefileContent);
        $this->assertStringContainsString('phpstan', $makefileContent);

        // Clean up
        $this->cleanWorkspace($basePath);
    }

    /**
     * Publish workflow fails without force when files exist.
     */
    #[Test]
    public function publish_fails_without_force_when_files_exist(): void
    {
        $basePath     = $this->app->basePath();
        $makefilePath = $basePath . '/Makefile';

        // Create existing file
        File::put($makefilePath, '# Existing Makefile');

        // Attempt publish without force
        $exitCode = Artisan::call('dev-tools:publish', [
            '--no-interaction' => true,  // ← AJOUTE CECI
        ]);

        // Should succeed (not fail!) - command doesn't return error codes
        $this->assertEquals(0, $exitCode, 'Command succeeds but skips existing files');

        // Original file should be unchanged
        $content = File::get($makefilePath);
        $this->assertStringContainsString('Existing Makefile', $content);

        // Clean up
        File::delete($makefilePath);
    }

    /**
     * Publish workflow with force overwrite.
     */
    #[Test]
    public function publish_overwrites_existing_files_with_force(): void
    {
        $basePath     = $this->app->basePath();
        $makefilePath = $basePath . '/Makefile';

        // Create initial file
        File::put($makefilePath, '# Original Makefile');
        $originalContent = File::get($makefilePath);

        // Publish with force
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        // Verify file was replaced
        $newContent = File::get($makefilePath);
        $this->assertNotEquals($originalContent, $newContent);
        $this->assertStringContainsString('help', $newContent);

        // Clean up
        File::delete($makefilePath);
    }

    /**
     * Clean workspace after tests.
     */
    private function cleanWorkspace(string $basePath): void
    {
        $files = [
            $basePath . '/Makefile',
            $basePath . '/pint.json',
            $basePath . '/phpstan.neon',
        ];

        foreach ($files as $file) {
            if (is_link($file)) {
                unlink($file);
            }
            elseif (File::exists($file)) {
                File::delete($file);
            }
        }

        // Clean hooks directory if exists
        $hooksDir = $basePath . '/.githooks';

        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }
    }
}
