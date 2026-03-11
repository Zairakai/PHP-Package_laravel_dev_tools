<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Composer\IO\BufferIO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zairakai\LaravelDevTools\Services\GitlabCiSynchronizer;

/**
 * Tests for GitlabCiSynchronizer.
 *
 * Uses a temporary directory as projectRoot to isolate filesystem side effects.
 * InstalledVersions is bypassed via the injectable $versionResolver callable.
 */
final class GitlabCiSynchronizerTest extends TestCase
{
    private BufferIO $bufferIO;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolated temp directory per test — cleaned in tearDown
        $this->tmpDir = sys_get_temp_dir() . '/ldt-ci-sync-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);

        $this->bufferIO = new BufferIO();
    }

    protected function tearDown(): void
    {
        // Remove temp directory and all contents
        $this->removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideMultiIncludeFiles(): array
    {
        return [
            'dev-tools first, GitLab template second (in sync)' => [
                "include:\n"
                . "  - project: 'zairakai/php-packages/laravel-dev-tools'\n"
                . "    ref: v1.3.0\n"
                . "    file: '.gitlab/ci/pipeline-php-package.yml'\n"
                . "  - template: Jobs/Secret-Detection.gitlab-ci.yml\n",
                false, // already in sync → no output
            ],
            'GitLab template first, dev-tools second (out of sync)' => [
                "include:\n"
                . "  - template: Jobs/Secret-Detection.gitlab-ci.yml\n"
                . "  - project: 'zairakai/php-packages/laravel-dev-tools'\n"
                . "    ref: v1.0.0\n"
                . "    file: '.gitlab/ci/pipeline-php-package.yml'\n",
                true, // out of sync → warn
            ],
            'no dev-tools include at all' => [
                "include:\n"
                . "  - template: Jobs/Secret-Detection.gitlab-ci.yml\n",
                false, // not our template → ignore silently
            ],
        ];
    }

    // =========================================================================
    // Multiple include blocks
    // =========================================================================

    #[DataProvider('provideMultiIncludeFiles')]
    #[Test]
    public function it_detects_dev_tools_include_among_multiple_includes(string $content, bool $expectOutput): void
    {
        $this->writeGitlabCi($content);

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: false);

        if ($expectOutput) {
            $this->assertNotSame('', $this->bufferIO->getOutput());
        }
        else {
            $this->assertSame('', $this->bufferIO->getOutput());
        }
    }

    // =========================================================================
    // containsDevToolsInclude — CI_TEMPLATE_FILE branch
    // =========================================================================

    #[Test]
    public function it_detects_self_hosted_pipeline_via_local_include(): void
    {
        // Simulates laravel-dev-tools itself, which uses include:local: instead
        // of include:project: — covers the CI_TEMPLATE_FILE branch of containsDevToolsInclude()
        $this->writeGitlabCi(
            "include:\n"
            . "  - local: '.gitlab/ci/pipeline-php-package.yml'\n"
            . "\n"
            . "variables:\n"
            . '  CACHE_KEY: "laravel-dev-tools"' . "\n"
            . '  PACKAGIST_PACKAGE: "zairakai/laravel-dev-tools"' . "\n",
        );

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: false);

        // File matched via CI_TEMPLATE_FILE — ref is unparseable without project: block
        // so we expect the "Could not parse ref" warning
        $this->assertStringContainsString('Could not parse ref:', $this->bufferIO->getOutput());
    }

    // =========================================================================
    // fetchRawVersion — production path (no resolver injected)
    // =========================================================================

    #[Test]
    public function it_does_not_crash_when_no_version_resolver_is_injected(): void
    {
        // Instantiate without versionResolver to exercise the InstalledVersions path.
        // In the test environment, InstalledVersions may return null, 'dev-main', or
        // throw OutOfBoundsException — all handled gracefully. The only assertion is
        // that synchronize() completes without error and does not modify any file.
        $gitlabCiSynchronizer = new GitlabCiSynchronizer(
            io: $this->bufferIO,
            projectRoot: $this->tmpDir,
            // no versionResolver — forces the InstalledVersions branch in fetchRawVersion()
        );

        // Does not throw regardless of what InstalledVersions returns
        $gitlabCiSynchronizer->synchronize(autoFix: false);

        // No file created — version is null or non-tag → early return
        $this->assertFileDoesNotExist($this->tmpDir . '/.gitlab-ci.yml');
    }

    #[Test]
    public function it_does_not_modify_file_when_auto_fix_is_disabled(): void
    {
        $original = $this->makeGitlabCiWithRef('v1.2.0');
        $this->writeGitlabCi($original);

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: false);

        $this->assertSame($original, $this->readGitlabCi());
    }

    #[Test]
    public function it_does_nothing_on_auto_fix_when_already_in_sync(): void
    {
        $content = $this->makeGitlabCiWithRef('v1.3.0');
        $this->writeGitlabCi($content);

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: true);

        $this->assertSame($content, $this->readGitlabCi());
        $this->assertSame('', $this->bufferIO->getOutput());
    }

    // =========================================================================
    // File present, already in sync
    // =========================================================================

    #[Test]
    public function it_does_nothing_when_ref_matches_installed_version(): void
    {
        $this->writeGitlabCi($this->makeGitlabCiWithRef('v1.3.0'));

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: false);

        $this->assertSame('', $this->bufferIO->getOutput());
    }

    #[Test]
    public function it_exits_include_block_on_top_level_key_and_ignores_further_content(): void
    {
        // project: found but ref: is absent — parsing continues past the include block
        // into "variables:" (a top-level key with no leading whitespace).
        // This triggers shouldSkipLine() lines 265-266: $inIncludeBlock and
        // $foundProject are reset, then the method returns null → "Could not parse ref".
        $this->writeGitlabCi(
            "include:\n"
            . "  - project: 'zairakai/php-packages/laravel-dev-tools'\n"
            . "    file: '.gitlab/ci/pipeline-php-package.yml'\n"
            . "\n"
            . "variables:\n"
            . '  CACHE_KEY: "my-package"' . "\n",
        );

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: false);

        // ref: absent → parser resets at "variables:" → currentRef null
        $this->assertStringContainsString('Could not parse ref:', $this->bufferIO->getOutput());
    }

    #[Test]
    public function it_handles_quoted_ref_with_double_quotes(): void
    {
        $this->writeGitlabCi(
            "include:\n"
            . "  - project: 'zairakai/php-packages/laravel-dev-tools'\n"
            . '    ref: "v1.2.0"' . "\n"
            . "    file: '.gitlab/ci/pipeline-php-package.yml'\n",
        );

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: true);

        $this->assertStringContainsString('v1.3.0', $this->readGitlabCi());
    }

    // =========================================================================
    // Edge cases — ref formats
    // =========================================================================

    #[Test]
    public function it_handles_quoted_ref_with_single_quotes(): void
    {
        $this->writeGitlabCi(
            "include:\n"
            . "  - project: 'zairakai/php-packages/laravel-dev-tools'\n"
            . "    ref: 'v1.2.0'\n"
            . "    file: '.gitlab/ci/pipeline-php-package.yml'\n",
        );

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: true);

        $this->assertStringContainsString('v1.3.0', $this->readGitlabCi());
    }

    #[Test]
    public function it_handles_version_already_v_prefixed_without_double_prefix(): void
    {
        $gitlabCiSynchronizer = new GitlabCiSynchronizer(
            io: $this->bufferIO,
            projectRoot: $this->tmpDir,
            versionResolver: static fn (): string => 'v1.3.0',
        );

        $this->writeGitlabCi($this->makeGitlabCiWithRef('v1.2.0'));

        $gitlabCiSynchronizer->synchronize(autoFix: true);

        // Should be "v1.3.0", never "vv1.3.0"
        $this->assertStringContainsString('ref: v1.3.0', $this->readGitlabCi());
        $this->assertStringNotContainsString('vv1.3.0', $this->readGitlabCi());
    }

    // =========================================================================
    // File present but does not include our template
    // =========================================================================

    #[Test]
    public function it_ignores_file_that_does_not_include_dev_tools_template(): void
    {
        $this->writeGitlabCi(
            "include:\n"
            . "  - template: Jobs/Secret-Detection.gitlab-ci.yml\n"
            . "\n"
            . "stages:\n"
            . "  - test\n",
        );

        $this->makeSynchronizer()->synchronize(autoFix: false);

        $this->assertSame('', $this->bufferIO->getOutput());
    }

    #[Test]
    public function it_ignores_file_when_version_resolver_returns_null(): void
    {
        $this->writeGitlabCi($this->makeGitlabCiWithRef('v1.2.0'));

        $gitlabCiSynchronizer = new GitlabCiSynchronizer(
            io: $this->bufferIO,
            projectRoot: $this->tmpDir,
            versionResolver: static fn (): ?string => null,
        );

        $gitlabCiSynchronizer->synchronize(autoFix: true);

        // File must not be modified
        $this->assertStringContainsString('ref: v1.2.0', $this->readGitlabCi());
        $this->assertSame('', $this->bufferIO->getOutput());
    }

    // =========================================================================
    // Missing .gitlab-ci.yml
    // =========================================================================

    #[Test]
    public function it_logs_info_and_suggests_artisan_when_no_composer_json(): void
    {
        // No composer.json in tmpDir → falls back to application (artisan) command
        $gitlabCiSynchronizer = $this->makeSynchronizer();

        $gitlabCiSynchronizer->synchronize(autoFix: false);

        $output = $this->bufferIO->getOutput();

        $this->assertStringContainsString('.gitlab-ci.yml not found', $output);
        $this->assertStringContainsString('php artisan dev-tools:publish --publish=gitlab-ci', $output);
    }

    #[Test]
    public function it_logs_success_message_after_auto_fix(): void
    {
        $this->writeGitlabCi($this->makeGitlabCiWithRef('v1.2.0'));

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: true);

        $output = $this->bufferIO->getOutput();

        $this->assertStringContainsString('v1.2.0', $output);
        $this->assertStringContainsString('v1.3.0', $output);
        $this->assertStringContainsString('.gitlab-ci.yml', $output);
    }

    // =========================================================================
    // Version normalization
    // =========================================================================

    #[Test]
    public function it_normalizes_version_without_v_prefix_from_resolver(): void
    {
        // Resolver returns "1.3.0" (no v prefix) — should still write "v1.3.0"
        $gitlabCiSynchronizer = new GitlabCiSynchronizer(
            io: $this->bufferIO,
            projectRoot: $this->tmpDir,
            versionResolver: static fn (): string => '1.3.0',
        );

        $this->writeGitlabCi($this->makeGitlabCiWithRef('v1.2.0'));

        $gitlabCiSynchronizer->synchronize(autoFix: true);

        $this->assertStringContainsString('ref: v1.3.0', $this->readGitlabCi());
    }

    #[Test]
    public function it_preserves_file_content_except_ref_on_auto_fix(): void
    {
        $this->writeGitlabCi($this->makeGitlabCiWithRef('v1.2.0'));

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: true);

        $updated = $this->readGitlabCi();

        // Other content preserved
        $this->assertStringContainsString('CACHE_KEY', $updated);
        $this->assertStringContainsString('zairakai/php-packages/laravel-dev-tools', $updated);
        $this->assertStringContainsString('pipeline-php-package.yml', $updated);
    }

    // =========================================================================
    // findRefInLines — edge cases
    // =========================================================================

    #[Test]
    public function it_returns_null_when_new_include_entry_starts_before_ref(): void
    {
        // project: found (foundProject=true) but a new include entry "- " appears
        // before any ref: line — covers line 241 (return null inside findRefInLines)
        $this->writeGitlabCi(
            "include:\n"
            . "  - project: 'zairakai/php-packages/laravel-dev-tools'\n"
            . "  - template: Jobs/Secret-Detection.gitlab-ci.yml\n",
        );

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: false);

        // ref: never found → currentRef null → "Could not parse ref" warning
        $this->assertStringContainsString('Could not parse ref:', $this->bufferIO->getOutput());
    }

    #[Test]
    public function it_suggests_artisan_when_project_type_is_project(): void
    {
        $this->writeComposerJson(['name' => 'acme/my-app', 'type' => 'project']);

        $this->makeSynchronizer()->synchronize(autoFix: false);

        $output = $this->bufferIO->getOutput();

        $this->assertStringContainsString('.gitlab-ci.yml not found', $output);
        $this->assertStringContainsString('php artisan dev-tools:publish --publish=gitlab-ci', $output);
        $this->assertStringNotContainsString('setup-package.sh', $output);
    }

    #[Test]
    public function it_suggests_bash_script_when_project_type_is_composer_plugin(): void
    {
        $this->writeComposerJson(['name' => 'acme/my-plugin', 'type' => 'composer-plugin']);

        $this->makeSynchronizer()->synchronize(autoFix: false);

        $output = $this->bufferIO->getOutput();

        $this->assertStringContainsString('.gitlab-ci.yml not found', $output);
        $this->assertStringContainsString('setup-package.sh --publish=gitlab-ci', $output);
        $this->assertStringNotContainsString('php artisan', $output);
    }

    #[Test]
    public function it_suggests_bash_script_when_project_type_is_library(): void
    {
        $this->writeComposerJson(['name' => 'acme/my-package', 'type' => 'library']);

        $this->makeSynchronizer()->synchronize(autoFix: false);

        $output = $this->bufferIO->getOutput();

        $this->assertStringContainsString('.gitlab-ci.yml not found', $output);
        $this->assertStringContainsString('setup-package.sh --publish=gitlab-ci', $output);
        $this->assertStringNotContainsString('php artisan', $output);
    }

    #[Test]
    public function it_suggests_publish_on_auto_fix_too_when_file_is_missing(): void
    {
        $gitlabCiSynchronizer = $this->makeSynchronizer();

        $gitlabCiSynchronizer->synchronize(autoFix: true);

        $this->assertStringContainsString('.gitlab-ci.yml not found', $this->bufferIO->getOutput());
    }

    // =========================================================================
    // Out of sync — auto-fix mode (post-update-cmd)
    // =========================================================================

    #[Test]
    public function it_updates_ref_when_auto_fix_is_enabled(): void
    {
        $this->writeGitlabCi($this->makeGitlabCiWithRef('v1.2.0'));

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: true);

        $this->assertStringContainsString('ref: v1.3.0', $this->readGitlabCi());
        $this->assertStringNotContainsString('ref: v1.2.0', $this->readGitlabCi());
    }

    // =========================================================================
    // Out of sync — warn mode (post-install-cmd)
    // =========================================================================

    #[Test]
    public function it_warns_when_ref_is_outdated_and_auto_fix_is_disabled(): void
    {
        $this->writeGitlabCi($this->makeGitlabCiWithRef('v1.2.0'));

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: false);

        $output = $this->bufferIO->getOutput();

        $this->assertStringContainsString('v1.2.0', $output);
        $this->assertStringContainsString('v1.3.0', $output);
        $this->assertStringContainsString('composer update', $output);
    }

    #[Test]
    public function it_warns_when_ref_line_cannot_be_parsed(): void
    {
        // Malformed YAML where ref: exists but has no parseable value
        $this->writeGitlabCi(
            "include:\n"
            . "  - project: 'zairakai/php-packages/laravel-dev-tools'\n"
            . "    ref:\n"
            . "    file: '.gitlab/ci/pipeline-php-package.yml'\n",
        );

        $this->makeSynchronizer('v1.3.0')->synchronize(autoFix: false);

        $this->assertStringContainsString('Could not parse ref:', $this->bufferIO->getOutput());
    }

    /**
     * Build a minimal valid .gitlab-ci.yml content with our include block.
     */
    private function makeGitlabCiWithRef(string $ref): string
    {
        // IMPORTANT: No leading indentation — the parser detects top-level keys
        // (like "variables:") by checking for absence of leading whitespace.
        // An indented heredoc would prevent shouldSkipLine() from resetting the
        // include block state, leaving branches on lines 265-266 uncovered.
        return "include:\n"
            . "  - project: 'zairakai/php-packages/laravel-dev-tools'\n"
            . "    ref: {$ref}\n"
            . "    file: '.gitlab/ci/pipeline-php-package.yml'\n"
            . "\n"
            . "variables:\n"
            . '  CACHE_KEY: "my-package"' . "\n";
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a GitlabCiSynchronizer with a fixed installed version.
     */
    private function makeSynchronizer(string $installedVersion = 'v1.3.0'): GitlabCiSynchronizer
    {
        return new GitlabCiSynchronizer(
            io: $this->bufferIO,
            projectRoot: $this->tmpDir,
            versionResolver: static fn (): string => ltrim($installedVersion, 'v'),
        );
    }

    /**
     * Read .gitlab-ci.yml from the temp directory.
     */
    private function readGitlabCi(): string
    {
        return (string) file_get_contents($this->tmpDir . '/.gitlab-ci.yml');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) as $item) {
            if ('.' === $item) {
                continue;
            }

            if ('..' === $item) {
                continue;
            }

            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }

    /**
     * Write a composer.json in the temp directory.
     *
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        file_put_contents(
            $this->tmpDir . '/composer.json',
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Write .gitlab-ci.yml in the temp directory.
     */
    private function writeGitlabCi(string $content): void
    {
        file_put_contents($this->tmpDir . '/.gitlab-ci.yml', $content);
    }
}
