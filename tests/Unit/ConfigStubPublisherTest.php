<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Zairakai\LaravelDevTools\Services\ConfigCopies;
use Zairakai\LaravelDevTools\Services\ConfigStubPublisher;
use Zairakai\LaravelDevTools\Services\GitHooksActivator;
use Zairakai\LaravelDevTools\Services\LaravelPublishOutput;
use Zairakai\LaravelDevTools\Tests\TestCase;

/**
 * Tests for ConfigStubPublisher - covers all publish methods, force logic,
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

        // Factory requires an OutputInterface - use BufferedOutput to capture output silently
        $bufferedOutput  = new BufferedOutput();
        $factory         = new Factory($bufferedOutput);

        $this->configStubPublisher = new ConfigStubPublisher(new LaravelPublishOutput($factory));

        $this->cleanDevToolsDir();
    }

    protected function tearDown(): void
    {
        $this->cleanDevToolsDir();

        parent::tearDown();
    }

    #[Test]
    public function a_forced_publication_keeps_a_copy_of_the_generated_pint_file_and_of_its_copy_at_the_root(): void
    {
        File::makeDirectory(
            $this->basePath . '/config/dev-tools',
            0755,
            true,
            true,
        );
        File::put($this->basePath . '/config/dev-tools/pint.json', '{"mine": 1}');
        File::put($this->basePath . '/pint.json', '{"root": 1}');

        $this->configStubPublisher->publishPint(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertSame(
            '{"mine": 1}',
            File::get((glob($this->basePath . '/.dev-tools-backup/*/config/dev-tools/pint.json') ?: [
                '',
            ])[0]),
        );
        $this->assertSame(
            '{"root": 1}',
            File::get((glob($this->basePath . '/.dev-tools-backup/*/pint.json') ?: [
                '',
            ])[0]),
        );
    }

    #[Test]
    public function a_forced_publication_keeps_a_copy_of_the_thin_file_of_the_root_that_it_writes_over(): void
    {
        File::put($this->basePath . '/phpstan.neon', "includes:\n  - vendor/old.neon\n");

        $this->configStubPublisher->publishPhpstan(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $copies = glob($this->basePath . '/.dev-tools-backup/*/phpstan.neon') ?: [];

        $this->assertCount(1, $copies);
        $this->assertStringContainsString('vendor/old.neon', File::get($copies[0]));
        $this->assertStringContainsString('config/dev-tools/phpstan.neon', File::get($this->basePath . '/phpstan.neon'));
    }

    #[Test]
    public function a_publication_without_force_keeps_no_copy(): void
    {
        File::put($this->basePath . '/phpstan.neon', "includes:\n  - vendor/old.neon\n");

        $this->configStubPublisher->publishPhpstan(
            $this->vendorPath,
            $this->basePath,
            false,
        );

        $this->assertStringContainsString('vendor/old.neon', File::get($this->basePath . '/phpstan.neon'));
        $this->assertSame([], glob($this->basePath . '/.dev-tools-backup/*') ?: []);
    }

    #[Test]
    public function available_groups_includes_gitlab_ci(): void
    {
        $groups = $this->configStubPublisher->availableGroups();

        $this->assertContains('gitlab-ci', $groups);
    }

    // publishGovernanceFiles
    // ================

    #[Test]
    public function available_groups_includes_governance(): void
    {
        $groups = $this->configStubPublisher->availableGroups();
        $this->assertContains('governance', $groups);
    }

    // availableGroups / availableKeys
    // ================

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
    public function available_keys_includes_governance(): void
    {
        $keys = $this->configStubPublisher->availableKeys();
        $this->assertContains('governance', $keys);
    }

    #[Test]
    public function available_keys_returns_individual_publishable_keys(): void
    {
        $keys = $this->configStubPublisher->availableKeys();

        $expectedKeys = [
            'phpstan',
            'rector',
            'insights',
            'baseline',
            'pint',
            'markdownlint',
            'markdownlintignore',
            'phpunit',
            'hooks',
            'gitlab-ci',
            'governance',
        ];

        foreach ($expectedKeys as $expectedKey) {
            $this->assertContains($expectedKey, $keys);
        }
    }

    // publishBaseline
    // ================

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
        File::makeDirectory(
            dirname($targetPath),
            0755,
            true,
            true,
        );
        File::put($targetPath, 'existing content');

        // Baseline is never overwritten - returns false when skipped
        $result = $this->configStubPublisher->publishBaseline($this->vendorPath, $this->basePath);

        $this->assertFalse($result);
        $this->assertStringContainsString('existing content', File::get($targetPath));
    }

    #[Test]
    public function publish_by_key_baseline_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'baseline',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/baseline.neon');
    }

    #[Test]
    public function publish_by_key_gitlab_ci_creates_file(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';

        $result = $this->configStubPublisher->publishByKey(
            'gitlab-ci',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($targetPath);
        File::delete($targetPath);
    }

    #[Test]
    public function publish_by_key_governance_creates_files(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'governance',
            $this->vendorPath,
            $this->basePath,
            false,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/CONTRIBUTING.md');
        $this->assertFileExists($this->basePath . '/CODE_OF_CONDUCT.md');
        $this->assertFileExists($this->basePath . '/SECURITY.md');

        File::delete($this->basePath . '/CONTRIBUTING.md');
        File::delete($this->basePath . '/CODE_OF_CONDUCT.md');
        File::delete($this->basePath . '/SECURITY.md');
    }

    #[Test]
    public function publish_by_key_hooks_activates_the_hooks_of_php_dev_tools(): void
    {
        $repository = $this->makeGitRepository();

        $result = $this->configStubPublisher->publishByKey(
            'hooks',
            $this->vendorPath,
            $repository,
            true,
        );

        $this->assertTrue($result);
        $this->assertSame(GitHooksActivator::HOOKS_PATH, $this->hooksPathOf($repository));

        File::deleteDirectory($repository);
    }

    #[Test]
    public function publish_by_key_insights_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'insights',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpinsights.php');
    }

    #[Test]
    public function publish_by_key_markdownlint_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'markdownlint',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/.markdownlint.json');
    }

    #[Test]
    public function publish_by_key_markdownlintignore_creates_the_file_of_the_root(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'markdownlintignore',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/.markdownlintignore');
    }

    #[Test]
    public function publish_by_key_phpstan_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'phpstan',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/phpstan.neon');
    }

    #[Test]
    public function publish_by_key_phpunit_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'phpunit',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpunit.xml');
    }

    // publishByKey
    // ================

    #[Test]
    public function publish_by_key_pint_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'pint',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/pint.json');
    }

    #[Test]
    public function publish_by_key_rector_creates_file(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'rector',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/rector.php');
    }

    #[Test]
    public function publish_by_key_unknown_returns_false(): void
    {
        $result = $this->configStubPublisher->publishByKey(
            'nonexistent-key',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertFalse($result);
    }

    // publishEnlightn
    // ================

    #[Test]
    public function publish_enlightn_creates_config_file(): void
    {
        $targetPath = $this->basePath . '/config/enlightn.php';

        $this->configStubPublisher->publishEnlightn(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertFileExists($targetPath);
        File::delete($targetPath);
    }

    #[Test]
    public function publish_enlightn_overwrites_with_force(): void
    {
        $targetPath = $this->basePath . '/config/enlightn.php';
        File::makeDirectory(
            dirname($targetPath),
            0755,
            true,
            true,
        );
        File::put($targetPath, '<?php return ["existing" => true];');

        $this->configStubPublisher->publishEnlightn(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertStringNotContainsString('existing', File::get($targetPath));
        File::delete($targetPath);
    }

    #[Test]
    public function publish_enlightn_skips_when_exists_without_force(): void
    {
        $targetPath = $this->basePath . '/config/enlightn.php';
        File::makeDirectory(
            dirname($targetPath),
            0755,
            true,
            true,
        );
        File::put($targetPath, '<?php return ["existing" => true];');

        $this->configStubPublisher->publishEnlightn(
            $this->vendorPath,
            $this->basePath,
            false,
        );

        $this->assertStringContainsString('existing', File::get($targetPath));
        File::delete($targetPath);
    }

    // publishGitlabCi
    // ================

    #[Test]
    public function publish_gitlab_ci_creates_file_at_project_root(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';

        $result = $this->configStubPublisher->publishGitlabCi(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($targetPath);
        File::delete($targetPath);
    }

    #[Test]
    public function publish_gitlab_ci_skips_when_exists_without_force(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';
        File::put($targetPath, 'existing content');

        $result = $this->configStubPublisher->publishGitlabCi(
            $this->vendorPath,
            $this->basePath,
            false,
        );

        $this->assertFalse($result);
        $this->assertStringContainsString('existing content', File::get($targetPath));
        File::delete($targetPath);
    }

    #[Test]
    public function publish_gitlab_ci_stub_contains_include_block(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';

        $this->configStubPublisher->publishGitlabCi(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $content = File::get($targetPath);

        $this->assertStringContainsString('zairakai/php-packages/laravel-dev-tools', $content);
        $this->assertStringContainsString('pipeline-php-package.yml', $content);
        $this->assertStringContainsString('CACHE_KEY', $content);
        $this->assertStringContainsString('PACKAGIST_PACKAGE', $content);
        File::delete($targetPath);
    }

    #[Test]
    public function publish_gitlab_ci_with_force_replaces_a_user_modified_file_and_keeps_a_copy(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';
        // User has modified the file (different from stub)
        File::put($targetPath, "include:
  - project: 'zairakai/php-packages/laravel-dev-tools'
    ref: 1.5.0
    file: '.gitlab/ci/pipeline-php-package.yml'
variables:
  CACHE_KEY: 'my-custom-project'
  PACKAGIST_PACKAGE: 'zairakai/my-package'
");

        $result = $this->configStubPublisher->publishGitlabCi(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertStringNotContainsString('my-custom-project', File::get($targetPath));
        $this->assertStringContainsString(
            'my-custom-project',
            File::get((glob($this->basePath . '/.dev-tools-backup/*/.gitlab-ci.yml') ?: [''])[0]),
        );
        File::delete($targetPath);
    }

    #[Test]
    public function publish_governance_files_creates_all_three_files(): void
    {
        $result = $this->configStubPublisher->publishGovernanceFiles(
            $this->vendorPath,
            $this->basePath,
            false,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/CONTRIBUTING.md');
        $this->assertFileExists($this->basePath . '/CODE_OF_CONDUCT.md');
        $this->assertFileExists($this->basePath . '/SECURITY.md');

        File::delete($this->basePath . '/CONTRIBUTING.md');
        File::delete($this->basePath . '/CODE_OF_CONDUCT.md');
        File::delete($this->basePath . '/SECURITY.md');
    }

    #[Test]
    public function publish_governance_files_overwrites_with_force(): void
    {
        $contributingPath = $this->basePath . '/CONTRIBUTING.md';
        File::put($contributingPath, '# My custom contributing guide');

        $this->configStubPublisher->publishGovernanceFiles(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertStringNotContainsString('My custom contributing guide', File::get($contributingPath));

        File::delete($contributingPath);
        File::delete($this->basePath . '/CODE_OF_CONDUCT.md');
        File::delete($this->basePath . '/SECURITY.md');
    }

    #[Test]
    public function publish_governance_files_replaces_placeholder(): void
    {
        // Provide a composer.json so the URL can be derived
        $composerPath = $this->basePath . '/composer.json';
        File::put($composerPath, '{"name": "zairakai/test-package"}');

        $this->configStubPublisher->publishGovernanceFiles(
            $this->vendorPath,
            $this->basePath,
            false,
        );

        $security = File::get($this->basePath . '/SECURITY.md');
        $this->assertStringNotContainsString('PACKAGE_GITLAB_ISSUES', $security);
        $this->assertStringContainsString('gitlab.com/zairakai', $security);

        File::delete($composerPath);
        File::delete($this->basePath . '/CONTRIBUTING.md');
        File::delete($this->basePath . '/CODE_OF_CONDUCT.md');
        File::delete($this->basePath . '/SECURITY.md');
    }

    #[Test]
    public function publish_governance_files_returns_false_when_stubs_dir_missing(): void
    {
        $result = $this->configStubPublisher->publishGovernanceFiles(
            '/nonexistent/vendor/path',
            $this->basePath,
            false,
        );
        $this->assertFalse($result);
    }

    #[Test]
    public function publish_governance_files_skips_user_modified_file_without_force(): void
    {
        $contributingPath = $this->basePath . '/CONTRIBUTING.md';
        File::put($contributingPath, '# My custom contributing guide');

        $this->configStubPublisher->publishGovernanceFiles(
            $this->vendorPath,
            $this->basePath,
            false,
        );

        $this->assertStringContainsString('My custom contributing guide', File::get($contributingPath));

        File::delete($contributingPath);
        File::delete($this->basePath . '/CODE_OF_CONDUCT.md');
        File::delete($this->basePath . '/SECURITY.md');
    }

    #[Test]
    public function publish_group_all_creates_all_files(): void
    {
        $result = $this->configStubPublisher->publishGroup(
            'all',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);

        foreach ([
            '/phpstan.neon',
            '/config/dev-tools/phpstan.neon',
            '/config/dev-tools/rector.php',
            '/config/dev-tools/phpinsights.php',
            '/config/dev-tools/baseline.neon',
            '/config/dev-tools/pint.json',
            '/pint.json',
            '/config/dev-tools/.markdownlint.json',
            '/.markdownlint.json',
            '/config/dev-tools/phpunit.xml',
            '/phpunit.xml',
        ] as $file) {
            $this->assertFileExists($this->basePath . $file, "Missing: {$file}");
        }

        File::deleteDirectory($this->basePath . '/.githooks');
    }

    #[Test]
    public function publish_group_gitlab_ci_creates_file(): void
    {
        $targetPath = $this->basePath . '/.gitlab-ci.yml';

        $result = $this->configStubPublisher->publishGroup(
            'gitlab-ci',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($targetPath);
        File::delete($targetPath);
    }

    // publishGroup
    // ================

    #[Test]
    public function publish_group_quality_creates_all_quality_files(): void
    {
        $result = $this->configStubPublisher->publishGroup(
            'quality',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);

        foreach ([
            '/phpstan.neon',
            '/config/dev-tools/phpstan.neon',
            '/config/dev-tools/rector.php',
            '/config/dev-tools/phpinsights.php',
            '/config/dev-tools/baseline.neon',
        ] as $file) {
            $this->assertFileExists($this->basePath . $file, "Missing: {$file}");
        }
    }

    #[Test]
    public function publish_group_style_creates_style_files(): void
    {
        $result = $this->configStubPublisher->publishGroup(
            'style',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/pint.json');
        $this->assertFileExists($this->basePath . '/.markdownlint.json');
    }

    #[Test]
    public function publish_group_testing_creates_phpunit_file(): void
    {
        $result = $this->configStubPublisher->publishGroup(
            'testing',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpunit.xml');
    }

    #[Test]
    public function publish_group_unknown_returns_false(): void
    {
        $result = $this->configStubPublisher->publishGroup(
            'nonexistent-group',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertFalse($result);
    }

    #[Test]
    public function publish_hooks_copies_nothing_into_the_project(): void
    {
        $repository = $this->makeGitRepository();

        $this->configStubPublisher->publishHooks(
            $this->vendorPath,
            $repository,
            true,
        );

        $this->assertDirectoryDoesNotExist($repository . '/.githooks');

        File::deleteDirectory($repository);
    }

    #[Test]
    public function publish_hooks_does_not_configure_a_parent_repository_from_an_invalid_git_directory(): void
    {
        $parent = $this->makeGitRepository();
        $child  = $parent . '/project';
        File::makeDirectory(
            $child . '/.git',
            0755,
            true,
        );

        $result = $this->configStubPublisher->publishHooks(
            $this->vendorPath,
            $child,
            true,
        );

        $this->assertFalse($result);
        $this->assertSame(
            '',
            $this->hooksPathOf($parent),
            'The parent repository must be left alone',
        );

        File::deleteDirectory($parent);
    }

    // publishHooks
    // ================

    #[Test]
    public function publish_hooks_points_core_hooks_path_at_the_hooks_of_php_dev_tools(): void
    {
        $repository = $this->makeGitRepository();

        $result = $this->configStubPublisher->publishHooks(
            $this->vendorPath,
            $repository,
            true,
        );

        $this->assertTrue($result);
        $this->assertSame(GitHooksActivator::HOOKS_PATH, $this->hooksPathOf($repository));

        File::deleteDirectory($repository);
    }

    #[Test]
    public function publish_hooks_returns_false_when_the_project_is_not_a_git_repository(): void
    {
        $directory = sys_get_temp_dir() . '/no-git-' . uniqid('', true);
        File::makeDirectory(
            $directory,
            0755,
            true,
        );

        $result = $this->configStubPublisher->publishHooks(
            $this->vendorPath,
            $directory,
            true,
        );

        $this->assertFalse($result);

        File::deleteDirectory($directory);
    }

    // Stub missing → graceful handling
    // ================

    #[Test]
    public function publish_insights_returns_false_when_stub_missing(): void
    {
        $result = $this->configStubPublisher->publishInsights(
            '/nonexistent/vendor/path',
            $this->basePath,
            true,
        );

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

        $result = $this->configStubPublisher->publishPhpunit(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        File::delete($artisanPath);
        File::delete($composerPath);
    }

    // publishPhpunit - Laravel app vs package detection
    // ================

    #[Test]
    public function publish_phpunit_uses_package_config_when_no_artisan(): void
    {
        // Testbench environment has no artisan at basePath → uses package config
        $result = $this->configStubPublisher->publishPhpunit(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertTrue($result);
        $this->assertFileExists($this->basePath . '/config/dev-tools/phpunit.xml');
    }

    #[Test]
    public function publish_pint_generates_the_complete_file_from_the_rules_of_php_dev_tools_and_copies_it(): void
    {
        $result = $this->configStubPublisher->publishPint($this->vendorPath, $this->basePath);

        $this->assertTrue($result);

        $config = json_decode(File::get($this->basePath . '/config/dev-tools/pint.json'), true);

        $this->assertIsArray($config);
        $this->assertSame('laravel', $config['preset']);
        $this->assertNotEmpty($config['rules'], 'the rules come from php-dev-tools');
        $this->assertContains(
            'storage',
            $config['finder']['exclude'],
            'the exclusions of Laravel are added',
        );
        $this->assertSame(
            File::get($this->basePath . '/config/dev-tools/pint.json'),
            File::get($this->basePath . '/pint.json'),
        );
    }

    #[Test]
    public function publish_pint_generates_the_file_again_only_with_force(): void
    {
        File::makeDirectory(
            $this->basePath . '/config/dev-tools',
            0755,
            true,
            true,
        );
        File::put($this->basePath . '/config/dev-tools/pint.json', '{"stale": true}');

        $this->configStubPublisher->publishPint($this->vendorPath, $this->basePath);
        $this->assertStringContainsString('stale', File::get($this->basePath . '/config/dev-tools/pint.json'));

        $this->configStubPublisher->publishPint(
            $this->vendorPath,
            $this->basePath,
            true,
        );
        $this->assertStringNotContainsString('stale', File::get($this->basePath . '/config/dev-tools/pint.json'));
        $this->assertStringNotContainsString('stale', File::get($this->basePath . '/pint.json'));
    }

    #[Test]
    public function publish_pint_keeps_the_file_of_the_project_and_copies_it_at_the_root(): void
    {
        File::makeDirectory(
            $this->basePath . '/config/dev-tools',
            0755,
            true,
            true,
        );
        File::put(
            $this->basePath . '/config/dev-tools/pint.json',
            '{"rules": {"single_quote": false}}',
        );

        $this->configStubPublisher->publishPint($this->vendorPath, $this->basePath);

        $this->assertSame(
            '{"rules": {"single_quote": false}}',
            File::get($this->basePath . '/config/dev-tools/pint.json'),
            'the changes of the project are kept',
        );
        $this->assertSame(
            File::get($this->basePath . '/config/dev-tools/pint.json'),
            File::get($this->basePath . '/pint.json'),
            'the root holds a copy',
        );
    }

    #[Test]
    public function publishing_everything_leaves_the_root_and_config_dev_tools_in_agreement(): void
    {
        $this->configStubPublisher->publishGroup(
            'all',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertSame([], ConfigCopies::problems($this->basePath));
    }

    #[Test]
    public function the_phpunit_file_of_config_dev_tools_goes_up_two_folders_and_the_copy_of_the_root_does_not(): void
    {
        $this->configStubPublisher->publishPhpunit(
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertStringContainsString('bootstrap="../../vendor/autoload.php"', File::get($this->basePath . '/config/dev-tools/phpunit.xml'));
        $this->assertStringContainsString('bootstrap="vendor/autoload.php"', File::get($this->basePath . '/phpunit.xml'));
    }

    #[Test]
    public function the_thin_files_of_the_root_point_at_config_dev_tools(): void
    {
        $this->configStubPublisher->publishGroup(
            'all',
            $this->vendorPath,
            $this->basePath,
            true,
        );

        $this->assertStringContainsString('config/dev-tools/phpstan.neon', File::get($this->basePath . '/phpstan.neon'));
        $this->assertStringContainsString('./config/dev-tools/.markdownlint.json', File::get($this->basePath . '/.markdownlint.json'));
        $this->assertFileDoesNotExist($this->basePath . '/rector.php', 'no editor reads it');
        $this->assertFileDoesNotExist($this->basePath . '/phpinsights.php', 'no editor reads it');
    }

    // Helpers
    // ================

    private function cleanDevToolsDir(): void
    {
        // The thin files that the tests publish to the root of the skeleton
        foreach ([
            'phpstan.neon',
            'rector.php',
            'phpinsights.php',
            'pint.json',
            '.markdownlint.json',
            '.markdownlintignore',
            'phpunit.xml',
        ] as $file) {
            File::delete($this->basePath . '/' . $file);
        }

        // The copies that a forced publication keeps
        File::deleteDirectory($this->basePath . '/.dev-tools-backup');

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

    private function hooksPathOf(string $repository): string
    {
        exec('git -C ' . escapeshellarg($repository) . ' config --get core.hooksPath', $output);

        return $output[0] ?? '';
    }

    /**
     * A real git repository: git config needs one.
     */
    private function makeGitRepository(): string
    {
        $path = sys_get_temp_dir() . '/hooks-' . uniqid('', true);
        File::makeDirectory(
            $path,
            0755,
            true,
        );
        exec('git init -q ' . escapeshellarg($path));

        return $path;
    }
}
