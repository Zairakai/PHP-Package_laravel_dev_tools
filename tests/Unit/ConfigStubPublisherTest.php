<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Zairakai\LaravelDevTools\Services\ConfigStubPublisher;
use Zairakai\LaravelDevTools\Tests\TestCase;

/**
 * Tests for ConfigStubPublisher — covers all publish methods, force logic,
 * user-modification protection, and error paths.
 */
final class ConfigStubPublisherTest extends TestCase
{
    private string $basePath;

    private ConfigStubPublisher $configStubPublisher;

    private string $vendorPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath   = $this->app->basePath();
        $this->vendorPath = dirname(__DIR__, 2);

        // Factory requires an OutputInterface — use BufferedOutput to capture output silently
        $bufferedOutput  = new BufferedOutput();
        $factory         = new Factory($bufferedOutput);

        $this->configStubPublisher = new ConfigStubPublisher($factory);

        $this->cleanDevToolsDir();
    }

    protected function tearDown(): void
    {
        $this->cleanDevToolsDir();

        parent::tearDown();
    }

    #[Test]
    public function available_groups_includes_gitlab_ci(): void
    {
        $groups = $this->configStubPublisher->availableGroups();

        $this->assertContains('gitlab-ci', $groups);
    }

    // =========================================================================
    // availableGroups / availableKeys
    // =========================================================================

    #[Test]
    public function available_groups_returns_expected_keys(): void
    {
        $groups = $this->configStubPublisher->availableGroups();

        $this->assertContains('quality', $groups);
        $this->assertContains('style', $groups);
        $this->assertContains('testing', $groups);
        $this->assertContains('hooks', $groups);
        $this->assertContains('all', $groups);
    }

    #[Test]
    public function available_keys_does_not_contain_all_meta_group(): void
    {
        $keys = $this->configStubPublisher->availableKeys();

        $this->assertNotContains('all', $keys);
    }

    #[Test]
    public function available_keys_includes_gitlab_ci(): void
    {
        $keys = $this->configStubPublisher->availableKeys();

        $this->assertContains('gitlab-ci', $keys);
    }

    #[Test]
    public function available_keys_returns_individual_publishable_keys(): void
    {
        $keys = $this->configStubPublisher->availableKeys();

        $expectedKeys = ['phpstan', 'rector', 'insights', 'baseline', 'pint', 'markdownlint', 'phpunit', 'hooks', 'gitlab-ci'];

        foreach ($expectedKeys as $expectedKey) {
            $this->assertContains($expectedKey, $keys);
        }
    }

    // =========================================================================
    // publishBaseline
    // =========================================================================

    #[Test]
    public function publish_baseline_creates_file_when_missing(): void
    {
        $targetPath = $this->basePath . '/config/dev-tools/baseline.neon';

        $result = $this->configStubPublisher->publishBaseline($this->vendorPath, $this->basePath);

        $this->assertTrue($result);
        $this->assertFileExists($targetPath);
    }

    #[Test]
    public function publish_baseline_skips_when_file_exists(): void
    {
        $targetPath = $this->basePath . '/config/dev-tools/baseline.neon';
        File::makeDirectory(dirname($targetPath), 0755, true, true);
        File::put($targetPath, 'existing content');

        // Baseline is never overwritten — returns false when skipped
        $result = $this->configStubPublisher->publishBaseline($this->vendorPath, $this->basePath);

        $this->assertFalse($result);
        $this->assertStringContainsString('existing content', File::get($targetPath));
    }

    #[Test]
    public function publish_by_key_baseline_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey('baseline', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/baseline.neon');
    }

    #[Test]
    public function publish_by_key_gitlab_ci_creates_file(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';

        $result = $this->configStubPublisher->publishByKey('gitlab-ci', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($targetPath);
        File::delete($targetPath);
    }

    #[Test]
    public function publish_by_key_hooks_creates_githooks_directory(): void
    {
        $result = $this->configStubPublisher->publishByKey('hooks', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertDirectoryExists($this->basePath . '/.githooks');

        File::deleteDirectory($this->basePath . '/.githooks');
    }

    #[Test]
    public function publish_by_key_insights_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey('insights', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/insights.php');
    }

    #[Test]
    public function publish_by_key_markdownlint_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey('markdownlint', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/markdownlint.json');
    }

    #[Test]
    public function publish_by_key_phpstan_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey('phpstan', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpstan.neon');
    }

    #[Test]
    public function publish_by_key_phpunit_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey('phpunit', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpunit.xml');
    }

    // =========================================================================
    // publishByKey
    // =========================================================================

    #[Test]
    public function publish_by_key_pint_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey('pint', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/pint.json');
    }

    #[Test]
    public function publish_by_key_rector_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey('rector', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/rector.php');
    }

    #[Test]
    public function publish_by_key_unknown_returns_false(): void
    {
        $result = $this->configStubPublisher->publishByKey('nonexistent-key', $this->vendorPath, $this->basePath, true);

        $this->assertFalse($result);
    }

    // =========================================================================
    // publishEnlightn
    // =========================================================================

    #[Test]
    public function publish_enlightn_creates_config_file(): void
    {
        $targetPath = $this->basePath . '/config/enlightn.php';

        $this->configStubPublisher->publishEnlightn($this->vendorPath, $this->basePath, true);

        $this->assertFileExists($targetPath);
        File::delete($targetPath);
    }

    #[Test]
    public function publish_enlightn_overwrites_with_force(): void
    {
        $targetPath = $this->basePath . '/config/enlightn.php';
        File::makeDirectory(dirname($targetPath), 0755, true, true);
        File::put($targetPath, '<?php return ["existing" => true];');

        $this->configStubPublisher->publishEnlightn($this->vendorPath, $this->basePath, true);

        $this->assertStringNotContainsString('existing', File::get($targetPath));
        File::delete($targetPath);
    }

    #[Test]
    public function publish_enlightn_skips_when_exists_without_force(): void
    {
        $targetPath = $this->basePath . '/config/enlightn.php';
        File::makeDirectory(dirname($targetPath), 0755, true, true);
        File::put($targetPath, '<?php return ["existing" => true];');

        $this->configStubPublisher->publishEnlightn($this->vendorPath, $this->basePath, false);

        $this->assertStringContainsString('existing', File::get($targetPath));
        File::delete($targetPath);
    }

    // =========================================================================
    // publishGitlabCi
    // =========================================================================

    #[Test]
    public function publish_gitlab_ci_creates_file_at_project_root(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';

        $result = $this->configStubPublisher->publishGitlabCi($this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($targetPath);
        File::delete($targetPath);
    }

    #[Test]
    public function publish_gitlab_ci_protects_user_modified_file_with_force(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';
        // User has modified the file (different from stub)
        File::put($targetPath, "include:
  - project: 'zairakai/php-packages/laravel-dev-tools'
    ref: v1.5.0
    file: '.gitlab/ci/pipeline-php-package.yml'
variables:
  CACHE_KEY: 'my-custom-project'
  PACKAGIST_PACKAGE: 'zairakai/my-package'
");

        $result = $this->configStubPublisher->publishGitlabCi($this->vendorPath, $this->basePath, true);

        $this->assertFalse($result);
        $this->assertStringContainsString('my-custom-project', File::get($targetPath));
        File::delete($targetPath);
    }

    #[Test]
    public function publish_gitlab_ci_skips_when_exists_without_force(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';
        File::put($targetPath, 'existing content');

        $result = $this->configStubPublisher->publishGitlabCi($this->vendorPath, $this->basePath, false);

        $this->assertFalse($result);
        $this->assertStringContainsString('existing content', File::get($targetPath));
        File::delete($targetPath);
    }

    #[Test]
    public function publish_gitlab_ci_stub_contains_include_block(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';

        $this->configStubPublisher->publishGitlabCi($this->vendorPath, $this->basePath, true);

        $content = File::get($targetPath);

        $this->assertStringContainsString('zairakai/php-packages/laravel-dev-tools', $content);
        $this->assertStringContainsString('pipeline-php-package.yml', $content);
        $this->assertStringContainsString('CACHE_KEY', $content);
        $this->assertStringContainsString('PACKAGIST_PACKAGE', $content);
        File::delete($targetPath);
    }

    #[Test]
    public function publish_group_all_creates_all_files(): void
    {
        $result = $this->configStubPublisher->publishGroup('all', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);

        foreach (['/config/dev-tools/phpstan.neon', '/config/dev-tools/rector.php', '/config/dev-tools/insights.php', '/config/dev-tools/baseline.neon', '/config/dev-tools/pint.json', '/config/dev-tools/markdownlint.json', '/config/dev-tools/phpunit.xml'] as $file) {
            $this->assertFileExists($this->basePath . $file, "Missing: {$file}");
        }

        File::deleteDirectory($this->basePath . '/.githooks');
    }

    #[Test]
    public function publish_group_gitlab_ci_creates_file(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';

        $result = $this->configStubPublisher->publishGroup('gitlab-ci', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($targetPath);
        File::delete($targetPath);
    }

    // =========================================================================
    // publishGroup
    // =========================================================================

    #[Test]
    public function publish_group_quality_creates_all_quality_files(): void
    {
        $result = $this->configStubPublisher->publishGroup('quality', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);

        foreach (['/config/dev-tools/phpstan.neon', '/config/dev-tools/rector.php', '/config/dev-tools/insights.php', '/config/dev-tools/baseline.neon'] as $file) {
            $this->assertFileExists($this->basePath . $file, "Missing: {$file}");
        }
    }

    #[Test]
    public function publish_group_style_creates_style_files(): void
    {
        $result = $this->configStubPublisher->publishGroup('style', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/pint.json');
        $this->assertFileExists($this->basePath . '/config/dev-tools/markdownlint.json');
    }

    #[Test]
    public function publish_group_testing_creates_phpunit_file(): void
    {
        $result = $this->configStubPublisher->publishGroup('testing', $this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpunit.xml');
    }

    #[Test]
    public function publish_group_unknown_returns_false(): void
    {
        $result = $this->configStubPublisher->publishGroup('nonexistent-group', $this->vendorPath, $this->basePath, true);

        $this->assertFalse($result);
    }

    // =========================================================================
    // publishHooks
    // =========================================================================

    #[Test]
    public function publish_hooks_creates_githooks_directory_and_files(): void
    {
        $hooksDir = $this->basePath . '/.githooks';

        $result = $this->configStubPublisher->publishHooks($this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertDirectoryExists($hooksDir);

        foreach (['commit-msg', 'pre-commit', 'pre-push', 'prepare-commit-msg'] as $hook) {
            $this->assertFileExists($hooksDir . '/' . $hook, "Missing hook: {$hook}");
            $this->assertTrue(is_executable($hooksDir . '/' . $hook), "Hook not executable: {$hook}");
        }

        File::deleteDirectory($hooksDir);
    }

    #[Test]
    public function publish_hooks_overwrites_existing_hooks_with_force(): void
    {
        $hooksDir  = $this->basePath . '/.githooks';
        $preCommit = $hooksDir . '/pre-commit';

        File::makeDirectory($hooksDir, 0755, true, true);
        File::put($preCommit, '#!/bin/bash' . PHP_EOL . 'echo "existing"');

        $this->configStubPublisher->publishHooks($this->vendorPath, $this->basePath, true);

        $this->assertStringNotContainsString('existing', File::get($preCommit));
        File::deleteDirectory($hooksDir);
    }

    #[Test]
    public function publish_hooks_returns_false_when_stubs_dir_missing(): void
    {
        $result = $this->configStubPublisher->publishHooks('/nonexistent/vendor/path', $this->basePath, true);

        $this->assertFalse($result);
    }

    #[Test]
    public function publish_hooks_skips_existing_hooks_without_force(): void
    {
        $hooksDir  = $this->basePath . '/.githooks';
        $preCommit = $hooksDir . '/pre-commit';

        File::makeDirectory($hooksDir, 0755, true, true);
        File::put($preCommit, '#!/bin/bash' . PHP_EOL . 'echo "existing"');

        $this->configStubPublisher->publishHooks($this->vendorPath, $this->basePath, false);

        $this->assertStringContainsString('existing', File::get($preCommit));
        File::deleteDirectory($hooksDir);
    }

    // =========================================================================
    // Stub missing → graceful handling
    // =========================================================================

    #[Test]
    public function publish_insights_returns_false_when_stub_missing(): void
    {
        $result = $this->configStubPublisher->publishInsights('/nonexistent/vendor/path', $this->basePath, true);

        $this->assertFalse($result);
    }

    #[Test]
    public function publish_phpunit_uses_app_config_when_laravel_detected(): void
    {
        // Simulate a Laravel application: artisan file + laravel/framework in composer.json
        $artisanPath  = $this->basePath . '/artisan';
        $composerPath = $this->basePath . '/composer.json';

        File::put($artisanPath, '#!/usr/bin/env php');
        File::put($composerPath, '{"require": {"laravel/framework": "^11.0"}}');

        $result = $this->configStubPublisher->publishPhpunit($this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        File::delete($artisanPath);
        File::delete($composerPath);
    }

    // =========================================================================
    // publishPhpunit — Laravel app vs package detection
    // =========================================================================

    #[Test]
    public function publish_phpunit_uses_package_config_when_no_artisan(): void
    {
        // Testbench environment has no artisan at basePath → uses package config
        $result = $this->configStubPublisher->publishPhpunit($this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpunit.xml');
    }

    #[Test]
    public function publish_pint_overwrites_unmodified_file_with_force(): void
    {
        $targetPath = $this->basePath . '/config/dev-tools/pint.json';
        $stubPath   = $this->vendorPath . '/config/pint.json';

        File::makeDirectory(dirname($targetPath), 0755, true, true);
        // Identical to stub → not user-modified → force overwrites
        File::copy($stubPath, $targetPath);

        $result = $this->configStubPublisher->publishPint($this->vendorPath, $this->basePath, true);

        $this->assertTrue($result);
    }

    #[Test]
    public function publish_pint_protects_user_modified_file_with_force(): void
    {
        $targetPath = $this->basePath . '/config/dev-tools/pint.json';
        File::makeDirectory(dirname($targetPath), 0755, true, true);

        // Content different from the stub → user-modified
        File::put($targetPath, '{"custom-user-content": true, "preset": "modified"}');

        $result = $this->configStubPublisher->publishPint($this->vendorPath, $this->basePath, true);

        $this->assertFalse($result);
        $this->assertStringContainsString('custom-user-content', File::get($targetPath));
    }

    // =========================================================================
    // publishPint — skip / force / user protection
    // =========================================================================

    #[Test]
    public function publish_pint_skips_when_exists_without_force(): void
    {
        $targetPath = $this->basePath . '/config/dev-tools/pint.json';
        File::makeDirectory(dirname($targetPath), 0755, true, true);
        File::put($targetPath, '{"existing": true}');

        $result = $this->configStubPublisher->publishPint($this->vendorPath, $this->basePath, false);

        $this->assertFalse($result);
        $this->assertStringContainsString('existing', File::get($targetPath));
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

        $enlightnPath = $this->basePath . '/config/enlightn.php';

        if (File::exists($enlightnPath)) {
            File::delete($enlightnPath);
        }

        $hooksDir = $this->basePath . '/.githooks';

        if (File::isDirectory($hooksDir)) {
            File::deleteDirectory($hooksDir);
        }
    }
}
