<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\TestCase;

/**
 * Tests for dev-tools:publish --publish mode.
 *
 * Covers handlePublish() and all group/key routing paths
 * in PublishToolingCommand.
 */
final class PublishCommandPublishModeTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = $this->app->basePath();
        $this->cleanDevToolsDir();
    }

    protected function tearDown(): void
    {
        $this->cleanDevToolsDir();

        parent::tearDown();
    }

    // =========================================================================
    // --publish=governance
    // =========================================================================

    #[Test]
    public function publish_mode_governance_creates_all_three_files(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'governance',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($this->basePath . '/CONTRIBUTING.md');
        $this->assertFileExists($this->basePath . '/CODE_OF_CONDUCT.md');
        $this->assertFileExists($this->basePath . '/SECURITY.md');

        File::delete($this->basePath . '/CONTRIBUTING.md');
        File::delete($this->basePath . '/CODE_OF_CONDUCT.md');
        File::delete($this->basePath . '/SECURITY.md');
    }

    #[Test]
    public function publish_mode_individual_key_baseline_creates_file(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'baseline',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($this->basePath . '/config/dev-tools/baseline.neon');
    }

    #[Test]
    public function publish_mode_individual_key_insights_creates_file(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'insights',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($this->basePath . '/config/dev-tools/insights.php');
    }

    #[Test]
    public function publish_mode_individual_key_markdownlint_creates_file(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'markdownlint',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($this->basePath . '/config/dev-tools/markdownlint.json');
    }

    #[Test]
    public function publish_mode_individual_key_phpstan_creates_file(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'phpstan',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpstan.neon');
    }

    #[Test]
    public function publish_mode_individual_key_phpunit_creates_file(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'phpunit',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpunit.xml');
    }

    // =========================================================================
    // --publish=<individual key>
    // =========================================================================

    #[Test]
    public function publish_mode_individual_key_pint_creates_file(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'pint',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($this->basePath . '/config/dev-tools/pint.json');
    }

    #[Test]
    public function publish_mode_individual_key_rector_creates_file(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'rector',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($this->basePath . '/config/dev-tools/rector.php');
    }

    // =========================================================================
    // --publish=quality
    // =========================================================================

    #[Test]
    public function publish_mode_quality_group_creates_quality_files(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'quality',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $this->assertFileExists($this->basePath . '/config/dev-tools/phpstan.neon');
        $this->assertFileExists($this->basePath . '/config/dev-tools/rector.php');
        $this->assertFileExists($this->basePath . '/config/dev-tools/insights.php');
        $this->assertFileExists($this->basePath . '/config/dev-tools/baseline.neon');
    }

    // =========================================================================
    // --publish=style
    // =========================================================================

    #[Test]
    public function publish_mode_style_group_creates_style_files(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'style',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $this->assertFileExists($this->basePath . '/config/dev-tools/pint.json');
        $this->assertFileExists($this->basePath . '/config/dev-tools/markdownlint.json');
    }

    // =========================================================================
    // --publish=testing
    // =========================================================================

    #[Test]
    public function publish_mode_testing_group_creates_phpunit_file(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'testing',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpunit.xml');
    }

    // =========================================================================
    // --publish=unknown → error + FAILURE exit code
    // =========================================================================

    #[Test]
    public function publish_mode_unknown_target_returns_failure(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => 'nonexistent-target',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(1, $exitCode, 'Unknown publish target should return failure');
    }

    #[Test]
    public function publish_mode_unknown_target_shows_valid_options(): void
    {
        Artisan::call('dev-tools:publish', [
            '--publish'        => 'nonexistent-target',
            '--no-interaction' => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('Unknown publish target', $output);
        $this->assertStringContainsString('quality', $output);
    }

    // =========================================================================
    // --publish without value → publish all
    // =========================================================================

    #[Test]
    public function publish_mode_without_value_publishes_all_groups(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', [
            '--publish'        => '',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $expectedFiles = [
            '/config/dev-tools/phpstan.neon',
            '/config/dev-tools/rector.php',
            '/config/dev-tools/insights.php',
            '/config/dev-tools/baseline.neon',
            '/config/dev-tools/pint.json',
            '/config/dev-tools/markdownlint.json',
            '/config/dev-tools/phpunit.xml',
        ];

        foreach ($expectedFiles as $expectedFile) {
            $this->assertFileExists($this->basePath . $expectedFile, "Missing: {$expectedFile}");
        }
    }

    #[Test]
    public function publish_mode_without_value_shows_done_message(): void
    {
        Artisan::call('dev-tools:publish', [
            '--publish'        => '',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('Done', $output);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function cleanDevToolsDir(): void
    {
        $devToolsDir = $this->basePath . '/config/dev-tools';

        if (File::isDirectory($devToolsDir)) {
            File::deleteDirectory($devToolsDir);
        }

        $hooksDir = $this->basePath . '/.githooks';

        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }

        $makefilePath = $this->basePath . '/Makefile';

        if (File::exists($makefilePath)) {
            File::delete($makefilePath);
        }

        foreach (['CONTRIBUTING.md', 'CODE_OF_CONDUCT.md', 'SECURITY.md'] as $governanceFile) {
            $path = $this->basePath . '/' . $governanceFile;

            if (File::exists($path)) {
                File::delete($path);
            }
        }
    }
}
