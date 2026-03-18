<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;

/**
 * Publishes configuration stubs from the vendor package into the project.
 *
 * Handles force overwrites, protects user-modified files, and provides
 * console feedback for each operation.
 *
 * Publishing groups:
 *   - quality    : phpstan, rector, insights, baseline
 *   - style      : pint, markdownlint
 *   - testing    : phpunit
 *   - hooks      : .githooks/ directory
 *   - gitlab-ci  : .gitlab-ci.yml (opt-in, not in 'all')
 *   - governance : CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md (opt-in, not in 'all')
 *   - all        : all groups above except gitlab-ci and governance
 *
 * Each public method corresponds to one publishable asset — intentional by design.
 * Splitting into sub-publishers would add indirection without benefit.
 */
final readonly class ConfigStubPublisher
{
    /**
     * Maps group names to their list of publishable keys.
     *
     * @var array<string, list<string>>
     */
    private const array GROUPS = [
        'quality'    => ['phpstan', 'rector', 'insights', 'baseline'],
        'style'      => ['pint', 'markdownlint'],
        'testing'    => ['phpunit'],
        'hooks'      => ['hooks'],
        'gitlab-ci'  => ['gitlab-ci'],
        'governance' => ['governance'],
        'all'        => ['phpstan', 'rector', 'insights', 'baseline', 'pint', 'markdownlint', 'phpunit', 'hooks'],
    ];

    public function __construct(
        private Factory $componentsFactory,
    ) {}

    /**
     * Returns valid publishable group names.
     *
     * @return array<int, string>
     */
    public function availableGroups(): array
    {
        return array_keys(self::GROUPS);
    }

    /**
     * Returns valid publishable individual key names.
     *
     * @return array<int, string>
     */
    public function availableKeys(): array
    {
        // All unique keys across all groups except 'all' (which is a meta-group)
        return array_values(array_unique(array_merge(...array_values(
            array_filter(self::GROUPS, fn (string $k): bool => 'all' !== $k, ARRAY_FILTER_USE_KEY),
        ))));
    }

    /**
     * Publish the PHPStan baseline stub.
     * Never force-overwritten — it accumulates user-accepted errors.
     */
    public function publishBaseline(string $vendorPath, string $basePath): bool
    {
        return $this->publishStub(
            stubFile: $vendorPath . '/stubs/baseline.neon.stub',
            targetFile: $basePath . '/config/dev-tools/baseline.neon',
            // Baseline is NEVER overwritten even with --force — it contains accepted errors
            force: false,
            label: 'config/dev-tools/baseline.neon',
            protectUserChanges: false,
        );
    }

    /**
     * Publish a single file by its key name.
     *
     * @param string $key        One of: phpstan, rector, insights, baseline, pint, markdownlint, phpunit, hooks
     * @param string $vendorPath Absolute path to the vendor package root
     * @param string $basePath   Absolute path to the project root
     * @param bool   $force      Overwrite existing files if true
     */
    public function publishByKey(string $key, string $vendorPath, string $basePath, bool $force): bool
    {
        return match ($key) {
            'phpstan'      => $this->publishPhpstan($vendorPath, $basePath, $force),
            'rector'       => $this->publishRector($vendorPath, $basePath, $force),
            'insights'     => $this->publishInsights($vendorPath, $basePath, $force),
            'baseline'     => $this->publishBaseline($vendorPath, $basePath),
            'pint'         => $this->publishPint($vendorPath, $basePath, $force),
            'markdownlint' => $this->publishMarkdownlint($vendorPath, $basePath, $force),
            'phpunit'      => $this->publishPhpunit($vendorPath, $basePath, $force),
            'hooks'        => $this->publishHooks($vendorPath, $basePath, $force),
            'gitlab-ci'    => $this->publishGitlabCi($vendorPath, $basePath, $force),
            'governance'   => $this->publishGovernanceFiles($vendorPath, $basePath, $force),
            default        => $this->unknownKey($key),
        };
    }

    // =========================================================================
    // Public API — individual file publishers
    // =========================================================================

    /**
     * Publish the Enlightn configuration stub.
     * Not part of any group — installed automatically on first setup.
     */
    public function publishEnlightn(string $vendorPath, string $basePath, bool $force): void
    {
        $this->publishStub(
            stubFile: $vendorPath . '/stubs/enlightn.php.stub',
            targetFile: $basePath . '/config/enlightn.php',
            force: $force,
            label: 'config/enlightn.php',
            protectUserChanges: false,
        );
    }

    /**
     * Publish the GitLab CI pipeline stub (.gitlab-ci.yml) into the project root.
     *
     * The stub contains a minimal .gitlab-ci.yml that includes the centralized
     * pipeline template from zairakai/laravel-dev-tools. The ref: placeholder
     * (v0.0.0) is replaced with the currently installed package version by
     * GitlabCiSynchronizer on the next composer install/update.
     *
     * Not included in the 'all' group — the CI file sits at the project root,
     * not in config/dev-tools/, and requires intentional opt-in.
     */
    public function publishGitlabCi(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->publishStub(
            stubFile: $vendorPath . '/stubs/gitlab-ci.yml.stub',
            targetFile: $basePath . '/.gitlab-ci.yml',
            force: $force,
            label: '.gitlab-ci.yml',
            protectUserChanges: true,
        );
    }

    /**
     * Publish the full-stack GitLab CI pipeline stub (.gitlab-ci.yml) into the project root.
     *
     * Includes both PHP and JS pipelines with unified stages.
     */
    public function publishGitlabCiFullstack(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->publishStub(
            stubFile: $vendorPath . '/stubs/gitlab-ci.fullstack.yml.stub',
            targetFile: $basePath . '/.gitlab-ci.yml',
            force: $force,
            label: '.gitlab-ci.yml',
            protectUserChanges: true,
        );
    }

    /**
     * Publish governance files (CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md) to project root.
     *
     * Replaces PACKAGE_GITLAB_ISSUES placeholder with a URL derived from composer.json name.
     * Hash-protected: skips files modified by the user (use --force to overwrite).
     * Not included in 'all' — requires explicit opt-in (--publish=governance).
     */
    public function publishGovernanceFiles(string $vendorPath, string $basePath, bool $force): bool
    {
        $stubsDir = $vendorPath . '/stubs/governance';

        if (! File::isDirectory($stubsDir)) {
            $this->componentsFactory->error('Governance stubs directory not found: ' . $stubsDir);

            return false;
        }

        $issuesUrl = $this->resolveIssuesUrl($basePath);

        $files = [
            'CONTRIBUTING.md'    => $basePath . '/CONTRIBUTING.md',
            'CODE_OF_CONDUCT.md' => $basePath . '/CODE_OF_CONDUCT.md',
            'SECURITY.md'        => $basePath . '/SECURITY.md',
        ];

        $results = [];

        foreach ($files as $filename => $targetFile) {
            $results[] = $this->publishGovernanceFile(
                stubFile: $stubsDir . '/' . $filename . '.stub',
                targetFile: $targetFile,
                label: $filename,
                issuesUrl: $issuesUrl,
                force: $force,
            );
        }

        return ! in_array(false, $results, true);
    }

    /**
     * Publish all files belonging to the given group.
     *
     * @param string $group      One of: quality, style, testing, hooks, all
     * @param string $vendorPath Absolute path to the vendor package root
     * @param string $basePath   Absolute path to the project root
     * @param bool   $force      Overwrite existing files if true
     */
    public function publishGroup(string $group, string $vendorPath, string $basePath, bool $force): bool
    {
        if (! array_key_exists($group, self::GROUPS)) {
            $this->componentsFactory->error(sprintf(
                'Unknown group "%s". Valid groups: %s',
                $group,
                implode(', ', array_keys(self::GROUPS)),
            ));

            return false;
        }

        foreach (self::GROUPS[$group] as $key) {
            $this->publishByKey($key, $vendorPath, $basePath, $force);
        }

        return true;
    }

    /**
     * Publish git hooks stubs into .githooks/ directory.
     *
     * Does NOT configure git core.hooksPath — that is handled by GitHooksManager.
     */
    public function publishHooks(string $vendorPath, string $basePath, bool $force): bool
    {
        $stubHooksDir    = $vendorPath . '/stubs/githooks';
        $projectHooksDir = $basePath . '/.githooks';
        $hooks           = ['commit-msg', 'pre-commit', 'pre-push', 'prepare-commit-msg'];

        if (! File::isDirectory($stubHooksDir)) {
            $this->componentsFactory->error('Git hooks stubs directory not found: ' . $stubHooksDir);

            return false;
        }

        $this->ensureDirectoryExists($projectHooksDir);

        $results = array_map(
            fn (string $hook): bool => $this->publishSingleHook($stubHooksDir, $projectHooksDir, $hook, $force),
            $hooks,
        );

        return ! in_array(false, $results, true);
    }

    /**
     * Publish the PHP Insights configuration stub.
     * User is expected to customize this file — protected against force overwrite.
     */
    public function publishInsights(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->publishStub(
            stubFile: $vendorPath . '/stubs/insights.php.stub',
            targetFile: $basePath . '/config/dev-tools/insights.php',
            force: $force,
            label: 'config/dev-tools/insights.php',
            protectUserChanges: true,
        );
    }

    /**
     * Publish the Markdownlint configuration into config/dev-tools/.
     */
    public function publishMarkdownlint(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->publishStub(
            stubFile: $vendorPath . '/.markdownlint.json',
            targetFile: $basePath . '/config/dev-tools/markdownlint.json',
            force: $force,
            label: 'config/dev-tools/markdownlint.json',
            protectUserChanges: true,
        );
    }

    // =========================================================================
    // Individual publishers
    // =========================================================================

    /**
     * Publish the PHPStan config (library.neon) into config/dev-tools/.
     */
    public function publishPhpstan(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->publishStub(
            stubFile: $vendorPath . '/config/library.neon',
            targetFile: $basePath . '/config/dev-tools/phpstan.neon',
            force: $force,
            label: 'config/dev-tools/phpstan.neon',
            protectUserChanges: true,
        );
    }

    /**
     * Publish the PHPUnit configuration stub.
     * Detects project type to choose between app and package config.
     */
    public function publishPhpunit(string $vendorPath, string $basePath, bool $force): bool
    {
        // Use app config for Laravel applications, library config for packages
        $isLaravelApp = file_exists($basePath . '/artisan')
            && file_exists($basePath . '/composer.json')
            && str_contains((string) file_get_contents($basePath . '/composer.json'), '"laravel/framework"');

        $stubFile = $isLaravelApp
            ? $vendorPath . '/config/phpunit-app.xml'
            : $vendorPath . '/config/phpunit.xml';

        return $this->publishStub(
            stubFile: $stubFile,
            targetFile: $basePath . '/config/dev-tools/phpunit.xml',
            force: $force,
            label: 'config/dev-tools/phpunit.xml',
            protectUserChanges: true,
        );
    }

    /**
     * Publish the Pint configuration into config/dev-tools/.
     */
    public function publishPint(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->publishStub(
            stubFile: $vendorPath . '/config/pint.json',
            targetFile: $basePath . '/config/dev-tools/pint.json',
            force: $force,
            label: 'config/dev-tools/pint.json',
            protectUserChanges: true,
        );
    }

    /**
     * Publish the Rector configuration stub.
     * User is expected to customize this file — protected against force overwrite.
     */
    public function publishRector(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->publishStub(
            stubFile: $vendorPath . '/stubs/rector.php.stub',
            targetFile: $basePath . '/config/dev-tools/rector.php',
            force: $force,
            label: 'config/dev-tools/rector.php',
            protectUserChanges: true,
        );
    }

    /**
     * Copy the stub file to the target location.
     */
    private function copyStub(string $stubFile, string $targetFile, string $label): bool
    {
        if (File::copy($stubFile, $targetFile)) {
            $this->componentsFactory->task('Copied: ' . $label);

            return true;
        }

        // @codeCoverageIgnoreStart
        $this->componentsFactory->error('Failed to copy: ' . $label);

        return false;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Create the given directory recursively if it does not already exist.
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    /**
     * Determine whether the target is a user-modified file that must not be overwritten.
     */
    private function isProtectedUserFile(
        string $stubFile,
        string $targetFile,
        bool $force,
        bool $protectUserChanges,
    ): bool {
        return $force
            && $protectUserChanges
            && File::exists($targetFile)
            && $this->isUserModified($stubFile, $targetFile);
    }

    private function isUserModified(string $stubFile, string $targetFile): bool
    {
        return hash_file('sha256', $stubFile) !== hash_file('sha256', $targetFile);
    }

    /**
     * Publish a single governance file with placeholder replacement.
     *
     * Logic (mirrors js-dev-tools publish_governance_file):
     *  - Process stub: replace PACKAGE_GITLAB_ISSUES placeholder
     *  - If target exists and no force: compare processed hash with target hash
     *      → same hash: refresh (user hasn't modified, re-copy)
     *      → different hash: skip (user has modified, protect)
     *  - If target exists and force: overwrite unconditionally
     *  - If target missing: copy
     */
    private function publishGovernanceFile(
        string $stubFile,
        string $targetFile,
        string $label,
        string $issuesUrl,
        bool $force,
    ): bool {
        if (! File::exists($stubFile)) {
            $this->componentsFactory->warn('Stub not found, skipping: ' . $label);

            return false;
        }

        // Replace placeholder in a temporary buffer for hash comparison
        $processed = str_replace('PACKAGE_GITLAB_ISSUES', $issuesUrl, (string) File::get($stubFile));
        $tempFile  = sys_get_temp_dir() . '/ldt_governance_' . md5($label);
        File::put($tempFile, $processed);

        $this->ensureDirectoryExists(dirname($targetFile));

        try {
            // Target does not exist yet — just copy
            if (! File::exists($targetFile)) {
                File::copy($tempFile, $targetFile);
                $this->componentsFactory->task('Copied: ' . $label);

                return true;
            }

            // Target exists, no force — compare processed content with target
            if (! $force) {
                $srcHash = hash_file('sha256', $tempFile);
                $tgtHash = hash_file('sha256', $targetFile);

                if ($srcHash === $tgtHash) {
                    // Identical — silently refresh
                    File::copy($tempFile, $targetFile);
                    $this->componentsFactory->task('Refreshed: ' . $label);

                    return true;
                }

                // User has modified the file — protect it
                $this->skip($label, 'contains user modifications (use --force to overwrite)', 'yellow');

                return false;
            }

            // Force — overwrite unconditionally
            File::copy($tempFile, $targetFile);
            $this->componentsFactory->task('Copied: ' . $label);

            return true;
        }
        finally {
            File::delete($tempFile);
        }
    }

    /**
     * Copy a single hook stub to the .githooks/ directory.
     */
    private function publishSingleHook(
        string $stubHooksDir,
        string $projectHooksDir,
        string $hook,
        bool $force,
    ): bool {
        $source      = $stubHooksDir . '/' . $hook;
        $destination = $projectHooksDir . '/' . $hook;

        if (! File::exists($source)) {
            // @codeCoverageIgnoreStart
            // Individual hook stub missing — impossible in practice (all hooks are packaged together).
            $this->componentsFactory->warn('Hook stub not found: ' . $hook);

            return false;
            // @codeCoverageIgnoreEnd
        }

        if (File::exists($destination) && ! $force) {
            $this->skip('.githooks/' . $hook, 'already exists (use --force to overwrite)');

            return false;
        }

        File::copy($source, $destination);
        chmod($destination, 0755);
        $this->componentsFactory->task('Copied: .githooks/' . $hook);

        return true;
    }

    // =========================================================================
    // Core publishing logic
    // =========================================================================

    /**
     * Core workflow for copying a stub file to its target location.
     *
     * @param string $stubFile           Absolute path to the stub file
     * @param string $targetFile         Absolute path to the target file
     * @param bool   $force              Whether to overwrite an existing file
     * @param string $label              Human-readable label for console output
     * @param bool   $protectUserChanges Skip overwrite if file differs from stub
     */
    private function publishStub(
        string $stubFile,
        string $targetFile,
        bool $force,
        string $label,
        bool $protectUserChanges,
    ): bool {
        if (! File::exists($stubFile)) {
            $this->componentsFactory->warn('Stub not found, skipping: ' . $label);

            return false;
        }

        $this->ensureDirectoryExists(dirname($targetFile));

        if ($this->shouldSkip($stubFile, $targetFile, $force, $protectUserChanges, $label)) {
            return false;
        }

        return $this->copyStub($stubFile, $targetFile, $label);
    }

    /**
     * Derive the GitLab issues URL from the project's composer.json name.
     *
     * Convention: zairakai/laravel-dev-tools → https://gitlab.com/zairakai/php-packages/laravel-dev-tools/-/issues
     */
    private function resolveIssuesUrl(string $basePath): string
    {
        $composerPath = $basePath . '/composer.json';

        if (! File::exists($composerPath)) {
            return 'TODO: add GitLab issues URL';
        }

        $composer = json_decode((string) File::get($composerPath), true);
        $raw      = is_array($composer) ? ($composer['name'] ?? '') : '';
        $name     = is_string($raw) ? $raw : '';

        if ('' === $name) {
            return 'TODO: add GitLab issues URL';
        }

        // Strip vendor prefix: zairakai/laravel-dev-tools → laravel-dev-tools
        $shortName = str_contains($name, '/') ? substr($name, strpos($name, '/') + 1) : $name;

        return 'https://gitlab.com/zairakai/php-packages/' . $shortName . '/-/issues';
    }

    /**
     * Determine whether publishing should be skipped for this file.
     */
    private function shouldSkip(
        string $stubFile,
        string $targetFile,
        bool $force,
        bool $protectUserChanges,
        string $label,
    ): bool {
        // File exists and no force flag — skip with a notice
        if (File::exists($targetFile) && ! $force) {
            $this->skip($label, 'already exists (use --force to overwrite)');

            return true;
        }

        // File exists, force is set, but user has modified it — refuse overwrite
        if ($this->isProtectedUserFile($stubFile, $targetFile, $force, $protectUserChanges)) {
            $this->skip($label, 'contains user modifications (refusing to overwrite)', 'red');

            return true;
        }

        return false;
    }

    /**
     * Display a skipped-file message in the console.
     */
    private function skip(string $label, string $reason, string $color = 'yellow'): void
    {
        $this->componentsFactory->twoColumnDetail(
            'Skipped: ' . $label,
            sprintf('<fg=%s>%s</>', $color, $reason),
        );
    }

    /**
     * Handle unknown publishable key.
     */
    private function unknownKey(string $key): bool
    {
        $this->componentsFactory->error(sprintf(
            'Unknown publishable key "%s". Valid keys: %s',
            $key,
            implode(', ', $this->availableKeys()),
        ));

        return false;
    }
}
