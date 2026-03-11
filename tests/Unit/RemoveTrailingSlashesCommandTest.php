<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class RemoveTrailingSlashesCommandTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = sys_get_temp_dir() . '/laravel-dev-tools-test-' . uniqid();
        File::makeDirectory($this->testDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function command_is_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey(
            'dev-tools:remove-trailing-slashes',
            $commands,
            'dev-tools:remove-trailing-slashes command should be registered',
        );
    }

    #[Test]
    public function dry_run_does_not_modify_files(): void
    {
        $bladeFile = $this->testDir . '/test.blade.php';
        $content   = '<input type="text" />';
        File::put($bladeFile, $content);

        $exitCode = Artisan::call('dev-tools:remove-trailing-slashes', [
            'path'      => $this->testDir,
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $unchangedContent = File::get($bladeFile);
        $this->assertEquals($content, $unchangedContent);
    }

    #[Test]
    public function dry_run_shows_warning(): void
    {
        $bladeFile = $this->testDir . '/test.blade.php';
        File::put($bladeFile, '<input type="text" />');

        $exitCode = Artisan::call('dev-tools:remove-trailing-slashes', [
            'path'      => $this->testDir,
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('DRY RUN: No files will be modified', $output);
    }

    #[Test]
    public function handles_empty_directory(): void
    {
        $exitCode = Artisan::call('dev-tools:remove-trailing-slashes', [
            'path' => $this->testDir,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Fixed 0 of 0 files', $output);
    }

    #[Test]
    public function processes_blade_files_in_directory(): void
    {
        $bladeFile = $this->testDir . '/test.blade.php';
        File::put($bladeFile, '<input type="text" />');

        $exitCode = Artisan::call('dev-tools:remove-trailing-slashes', [
            'path' => $this->testDir,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Scanning for trailing slashes in Blade files', $output);
    }

    #[Test]
    public function processes_multiple_blade_files(): void
    {
        File::put($this->testDir . '/file1.blade.php', '<input type="text" />');
        File::put($this->testDir . '/file2.blade.php', '<br />');
        File::put($this->testDir . '/file3.blade.php', '<hr />');

        $exitCode = Artisan::call('dev-tools:remove-trailing-slashes', [
            'path' => $this->testDir,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Fixed 3 of 3 files', $output);
    }

    #[Test]
    public function removes_trailing_slashes_from_self_closing_tags(): void
    {
        $bladeFile = $this->testDir . '/test.blade.php';
        $content   = '<input type="text" />';
        File::put($bladeFile, $content);

        $exitCode = Artisan::call('dev-tools:remove-trailing-slashes', [
            'path' => $this->testDir,
        ]);

        $this->assertEquals(0, $exitCode);

        $modifiedContent = File::get($bladeFile);
        $this->assertStringNotContainsString(' />', $modifiedContent);
        $this->assertStringContainsString('>', $modifiedContent);
    }

    #[Test]
    public function uses_default_resources_path_when_no_path_provided(): void
    {
        // When no path is provided, it should use Laravel's resourcePath()
        // This test verifies that code path is executed
        $exitCode = Artisan::call('dev-tools:remove-trailing-slashes');

        $this->assertEquals(0, $exitCode);

        // Should succeed (even if resources dir doesn't have blade files in test env)
        $output = Artisan::output();
        $this->assertStringContainsString('Scanning for trailing slashes in Blade files', $output);
    }

    #[Test]
    public function validates_directory_exists(): void
    {
        $nonExistentPath = $this->testDir . '/nonexistent';

        $exitCode = Artisan::call('dev-tools:remove-trailing-slashes', [
            'path' => $nonExistentPath,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString("Directory '{$nonExistentPath}' does not exist", $output);
    }
}
