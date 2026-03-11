<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class CleanAfterIdeHelperCommandTest extends TestCase
{
    #[Test]
    public function cleans_bootstrap_cache(): void
    {
        $cachePath = $this->app->bootstrapPath('cache');

        if (! File::isDirectory($cachePath)) {
            File::makeDirectory($cachePath, 0755, true);
        }

        File::put($cachePath . '/packages.php', '<?php return [];');
        File::put($cachePath . '/services.php', '<?php return [];');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('bootstrap cache files', $output);
    }

    #[Test]
    public function cleans_bootstrap_cache_skips_non_php_files(): void
    {
        $cachePath = $this->app->bootstrapPath('cache');

        if (! File::isDirectory($cachePath)) {
            File::makeDirectory($cachePath, 0755, true);
        }

        File::put($cachePath . '/packages.php', '<?php return [];');
        File::put($cachePath . '/readme.txt', 'readme');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        // Only PHP files deleted
        $this->assertFileDoesNotExist($cachePath . '/packages.php');
        $this->assertFileExists($cachePath . '/readme.txt');

        File::delete($cachePath . '/readme.txt');
    }

    #[Test]
    public function cleans_bootstrap_cache_with_gitignore_preserved(): void
    {
        $cachePath = $this->app->bootstrapPath('cache');

        if (! File::isDirectory($cachePath)) {
            File::makeDirectory($cachePath, 0755, true);
        }

        File::put($cachePath . '/packages.php', '<?php return [];');
        File::put($cachePath . '/.gitignore', '*');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        // .gitignore should be preserved
        $this->assertFileExists($cachePath . '/.gitignore');
        $this->assertFileDoesNotExist($cachePath . '/packages.php');
    }

    #[Test]
    public function cleans_compiled_views(): void
    {
        $viewCachePath = $this->app->storagePath('framework/views');

        if (! File::isDirectory($viewCachePath)) {
            File::makeDirectory($viewCachePath, 0755, true);
        }

        File::put($viewCachePath . '/compiled_view.php', '<?php // Compiled View');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Deleted', $output);
    }

    #[Test]
    public function cleans_ide_helper_files(): void
    {
        $basePath      = $this->app->basePath();
        $ideHelperFile = $basePath . '/_ide_helper.php';

        File::put($ideHelperFile, '<?php // IDE Helper');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Deleted: _ide_helper.php', $output);
        $this->assertFileDoesNotExist($ideHelperFile);
    }

    #[Test]
    public function cleans_multiple_ide_helper_files(): void
    {
        $basePath = $this->app->basePath();

        $files = [
            '_ide_helper.php',
            '_ide_helper_models.php',
            '.phpstorm.meta.php',
        ];

        foreach ($files as $file) {
            File::put($basePath . '/' . $file, '<?php // IDE Helper');
        }

        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        // Check all 3 IDE helper files were deleted
        $this->assertStringContainsString('Deleted: _ide_helper.php', $output);
        $this->assertStringContainsString('Deleted: _ide_helper_models.php', $output);
        $this->assertStringContainsString('Deleted: .phpstorm.meta.php', $output);

        foreach ($files as $file) {
            $this->assertFileDoesNotExist($basePath . '/' . $file);
        }
    }

    #[Test]
    public function command_is_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey(
            'dev-tools:clean-ide-helper',
            $commands,
            'dev-tools:clean-ide-helper command should be registered',
        );
    }

    #[Test]
    public function dry_run_does_not_delete_files(): void
    {
        $basePath      = $this->app->basePath();
        $ideHelperFile = $basePath . '/_ide_helper.php';

        File::put($ideHelperFile, '<?php // IDE Helper');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper', [
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('DRY RUN: No files will be deleted', $output);
        $this->assertStringContainsString('Would delete: _ide_helper.php', $output);
        $this->assertFileExists($ideHelperFile);

        File::delete($ideHelperFile);
    }

    #[Test]
    public function dry_run_does_not_show_cleanup_suggestions(): void
    {
        $basePath      = $this->app->basePath();
        $ideHelperFile = $basePath . '/_ide_helper.php';

        File::put($ideHelperFile, '<?php // IDE Helper');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper', [
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringNotContainsString('Consider running these additional cleanup commands:', $output);

        File::delete($ideHelperFile);
    }

    #[Test]
    public function dry_run_shows_bootstrap_cache_count(): void
    {
        $cachePath = $this->app->bootstrapPath('cache');

        if (! File::isDirectory($cachePath)) {
            File::makeDirectory($cachePath, 0755, true);
        }

        File::put($cachePath . '/packages.php', '<?php return [];');
        File::put($cachePath . '/services.php', '<?php return [];');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper', [
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Would delete', $output);
        $this->assertStringContainsString('bootstrap cache files', $output);

        // Files should still exist
        $this->assertFileExists($cachePath . '/packages.php');
        $this->assertFileExists($cachePath . '/services.php');

        // Cleanup
        File::delete($cachePath . '/packages.php');
        File::delete($cachePath . '/services.php');
    }

    #[Test]
    public function dry_run_shows_compiled_views_count(): void
    {
        $viewCachePath = $this->app->storagePath('framework/views');

        if (! File::isDirectory($viewCachePath)) {
            File::makeDirectory($viewCachePath, 0755, true);
        }

        File::put($viewCachePath . '/view1.php', '<?php // View 1');
        File::put($viewCachePath . '/view2.php', '<?php // View 2');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper', [
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Would delete', $output);
        $this->assertStringContainsString('compiled view files', $output);

        // Files should still exist
        $this->assertFileExists($viewCachePath . '/view1.php');
        $this->assertFileExists($viewCachePath . '/view2.php');

        // Cleanup
        File::cleanDirectory($viewCachePath);
    }

    #[Test]
    public function handles_missing_bootstrap_cache_directory(): void
    {
        // This test ensures the command doesn't fail when bootstrap/cache doesn't exist
        // Since it's a core Laravel directory, we can't delete it, but we verify
        // the command handles the case gracefully
        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        // Command should succeed even if bootstrap cache is missing/empty
        $output = Artisan::output();
        $this->assertStringContainsString('Cleaning IDE Helper generated files', $output);
    }

    #[Test]
    public function handles_missing_compiled_views_directory(): void
    {
        // Similar to bootstrap cache, storage/framework/views should exist in Laravel
        // but this test ensures the command handles it gracefully
        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        // Command should succeed
        $output = Artisan::output();
        $this->assertStringContainsString('Cleaning IDE Helper generated files', $output);
    }

    #[Test]
    public function handles_no_ide_helper_files(): void
    {
        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        // May also clean bootstrap cache, so just check command ran successfully
        $this->assertStringContainsString('Cleaning IDE Helper generated files', $output);
    }

    #[Test]
    public function invoke_method_executes_handle(): void
    {
        $basePath      = $this->app->basePath();
        $ideHelperFile = $basePath . '/_ide_helper.php';

        File::put($ideHelperFile, '<?php // IDE Helper');

        // Call command (which internally uses __invoke)
        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Cleaning IDE Helper generated files', $output);
        $this->assertFileDoesNotExist($ideHelperFile);
    }

    #[Test]
    public function shows_additional_cleanup_suggestions(): void
    {
        $basePath      = $this->app->basePath();
        $ideHelperFile = $basePath . '/_ide_helper.php';

        File::put($ideHelperFile, '<?php // IDE Helper');

        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Consider running these additional cleanup commands:', $output);
        $this->assertStringContainsString('php artisan config:clear', $output);
        $this->assertStringContainsString('php artisan route:clear', $output);
        $this->assertStringContainsString('php artisan view:clear', $output);
        $this->assertStringContainsString('composer dump-autoload', $output);
    }

    #[Test]
    public function shows_no_files_found_message_when_clean(): void
    {
        // Ensure no IDE helper files exist
        $basePath = $this->app->basePath();
        $files    = ['_ide_helper.php', '_ide_helper_models.php', '.phpstorm.meta.php'];

        foreach ($files as $file) {
            if (File::exists($basePath . '/' . $file)) {
                File::delete($basePath . '/' . $file);
            }
        }

        // Also ensure no bootstrap cache or compiled views
        $cachePath = $this->app->bootstrapPath('cache');

        if (File::isDirectory($cachePath)) {
            foreach (File::allFiles($cachePath) as $file) {
                if ($file->getExtension() === 'php' && $file->getFilename() !== '.gitignore') {
                    File::delete($file->getRealPath());
                }
            }
        }

        $viewCachePath = $this->app->storagePath('framework/views');

        if (File::isDirectory($viewCachePath)) {
            File::cleanDirectory($viewCachePath);
        }

        $exitCode = Artisan::call('dev-tools:clean-ide-helper');

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('No IDE Helper files found to clean', $output);
    }
}
