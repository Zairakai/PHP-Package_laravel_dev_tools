<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use RuntimeException;
use Zairakai\LaravelDevTools\Contracts\PublishOutput;

/**
 * Publishes configuration stubs from the vendor package into the project.
 *
 * Handles force overwrites, protects user-modified files, and provides
 * console feedback for each operation.
 *
 * Publishing groups:
 *   - quality    : phpstan, rector, insights, baseline
 *   - style      : pint, markdownlint, markdownlintignore
 *   - testing    : phpunit
 *   - hooks      : git hooks of laravel-dev-tools (core.hooksPath)
 *   - gitlab-ci  : .gitlab-ci.yml (opt-in, not in 'all')
 *   - governance : CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md (opt-in, not in 'all')
 *   - all        : all groups above except gitlab-ci and governance
 *
 * Each public method corresponds to one publishable asset - intentional by design.
 * Splitting into sub-publishers would add indirection without benefit.
 */
final class ConfigStubPublisher
{
    /**
     * Maps group names to their list of publishable keys.
     *
     * @var array<string, list<string>>
     */
    private const array GROUPS = [
        'quality'    => [
            'phpstan',
            'rector',
            'insights',
            'baseline',
        ],
        'style'      => [
            'pint',
            'markdownlint',
            'markdownlintignore',
        ],
        'testing'    => [
            'phpunit',
        ],
        'hooks'      => [
            'hooks',
        ],
        'gitlab-ci'  => [
            'gitlab-ci',
        ],
        'governance' => [
            'governance',
        ],
        'all'        => [
            'phpstan',
            'rector',
            'insights',
            'baseline',
            'pint',
            'markdownlint',
            'markdownlintignore',
            'phpunit',
            'hooks',
        ],
    ];

    /**
     * One backup per project: the copies of a run stay together in one folder, and two runs never share it.
     *
     * @var array<string, ConfigBackup>
     */
    private array $backups = [];

    public function __construct(
        private readonly PublishOutput $output,
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
            array_filter(
                self::GROUPS,
                fn (string $k): bool => 'all' !== $k,
                ARRAY_FILTER_USE_KEY,
            ),
        ))));
    }

    /**
     * Publish the PHPStan baseline stub.
     * Never force-overwritten - it accumulates user-accepted errors.
     */
    public function publishBaseline(string $vendorPath, string $basePath): bool
    {
        return $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/baseline.neon',
            targetFile: $basePath . '/config/dev-tools/baseline.neon',
            // Baseline is NEVER overwritten even with --force - it contains accepted errors
            force: false,
            label: 'config/dev-tools/baseline.neon',
            protectUserChanges: false,
        );
    }

    /**
     * Publish a single file by its key name.
     *
     * @param string $key        One of: phpstan, rector, insights, baseline, pint, markdownlint, markdownlintignore, phpunit, hooks
     * @param string $vendorPath Absolute path to the vendor package root
     * @param string $basePath   Absolute path to the project root
     * @param bool   $force      Overwrite existing files if true
     */
    public function publishByKey(
        string $key,
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        return match ($key) {
            'phpstan'            => $this->publishPhpstan(
                $vendorPath,
                $basePath,
                $force,
            ),
            'rector'             => $this->publishRector(
                $vendorPath,
                $basePath,
                $force,
            ),
            'insights'           => $this->publishInsights(
                $vendorPath,
                $basePath,
                $force,
            ),
            'baseline'           => $this->publishBaseline($vendorPath, $basePath),
            'pint'               => $this->publishPint(
                $vendorPath,
                $basePath,
                $force,
            ),
            'markdownlint'       => $this->publishMarkdownlint(
                $vendorPath,
                $basePath,
                $force,
            ),
            'markdownlintignore' => $this->publishMarkdownlintIgnore(
                $vendorPath,
                $basePath,
                $force,
            ),
            'phpunit'            => $this->publishPhpunit(
                $vendorPath,
                $basePath,
                $force,
            ),
            'hooks'              => $this->publishHooks(
                $vendorPath,
                $basePath,
                $force,
            ),
            'gitlab-ci'          => $this->publishGitlabCi(
                $vendorPath,
                $basePath,
                $force,
            ),
            'governance'         => $this->publishGovernanceFiles(
                $vendorPath,
                $basePath,
                $force,
            ),
            default              => $this->unknownKey($key),
        };
    }

    // Public API - individual file publishers
    // ================

    /**
     * Publish the Enlightn configuration stub.
     * Not part of any group - installed automatically on first setup.
     */
    public function publishEnlightn(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): void {
        $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/enlightn.php',
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
     * (0.0.0) is replaced with the currently installed package version by
     * GitlabCiSynchronizer on the next composer install/update.
     *
     * Not included in the 'all' group - the CI file sits at the project root,
     * not in config/dev-tools/, and requires intentional opt-in.
     */
    public function publishGitlabCi(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        return $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/gitlab-ci.yml',
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
    public function publishGitlabCiFullstack(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        return $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/gitlab-ci.fullstack.yml',
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
     * Not included in 'all' - requires explicit opt-in (--publish=governance).
     */
    public function publishGovernanceFiles(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        $stubsDir = $vendorPath . '/stubs/governance';

        if (! is_dir($stubsDir)) {
            $this->output->error('Governance stubs directory not found: ' . $stubsDir);

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
                stubFile: $stubsDir . '/' . $filename,
                targetFile: $targetFile,
                label: $filename,
                issuesUrl: $issuesUrl,
                force: $force,
                basePath: $basePath,
            );
        }

        return ! in_array(
            false,
            $results,
            true,
        );
    }

    /**
     * Publish all files belonging to the given group.
     *
     * @param string $group      One of: quality, style, testing, hooks, all
     * @param string $vendorPath Absolute path to the vendor package root
     * @param string $basePath   Absolute path to the project root
     * @param bool   $force      Overwrite existing files if true
     */
    public function publishGroup(
        string $group,
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        if (! array_key_exists($group, self::GROUPS)) {
            $this->output->error(sprintf(
                'Unknown group "%s". Valid groups: %s',
                $group,
                implode(', ', array_keys(self::GROUPS)),
            ));

            return false;
        }

        foreach (self::GROUPS[$group] as $key) {
            $this->publishByKey(
                $key,
                $vendorPath,
                $basePath,
                $force,
            );
        }

        return true;
    }

    /**
     * Activate the git hooks of laravel-dev-tools (core.hooksPath): they are read in place, nothing is published.
     */
    public function publishHooks(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        return new GitHooksActivator($this->output)->activate($basePath);
    }

    /**
     * Publish the PHP Insights configuration of the project into config/dev-tools/. No file at the root: no extension of
     * an editor reads it, the commands do.
     * User is expected to customize this file - protected against force overwrite.
     */
    public function publishInsights(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        return $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/phpinsights.php',
            targetFile: $basePath . '/config/dev-tools/phpinsights.php',
            force: $force,
            label: 'config/dev-tools/phpinsights.php',
            protectUserChanges: true,
        );
    }

    /**
     * Publish the Markdownlint configuration of the project into config/dev-tools/, and the thin file of the root that
     * extends it, where the extension of the editor reads it.
     */
    public function publishMarkdownlint(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        $published = $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/.markdownlint.json',
            targetFile: $basePath . '/config/dev-tools/.markdownlint.json',
            force: $force,
            label: 'config/dev-tools/.markdownlint.json',
            protectUserChanges: true,
        );

        $this->publishRootThin(
            $vendorPath,
            $basePath,
            '.markdownlint.json',
            $force,
        );

        return $published;
    }

    /**
     * Publish the Markdownlint ignore file into config/dev-tools/, and its copy at the root: an ignore file cannot
     * extend another file.
     */
    public function publishMarkdownlintIgnore(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        $published = $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/.markdownlintignore',
            targetFile: $basePath . '/config/dev-tools/.markdownlintignore',
            force: $force,
            label: 'config/dev-tools/.markdownlintignore',
            protectUserChanges: true,
        );

        $this->publishRootCopy(
            $basePath,
            '.markdownlintignore',
            $force,
        );

        return $published;
    }

    // Individual publishers
    // ================

    /**
     * Publish the PHPStan configuration of the project into config/dev-tools/, where it extends the one of this package,
     * and the thin file of the root that includes it, where the editor reads it.
     */
    public function publishPhpstan(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        $published = $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/phpstan.neon',
            targetFile: $basePath . '/config/dev-tools/phpstan.neon',
            force: $force,
            label: 'config/dev-tools/phpstan.neon',
            protectUserChanges: true,
        );

        $this->publishRootThin(
            $vendorPath,
            $basePath,
            'phpstan.neon',
            $force,
        );

        return $published;
    }

    /**
     * Publish the PHPUnit configuration into config/dev-tools/, and its copy at the root, where the editor reads it.
     * Detects project type to choose between app and package config.
     *
     * PHPUnit reads the paths of its file from the folder of that file: the one of config/dev-tools/ goes up two folders,
     * the copy of the root does not (ConfigCopies).
     */
    public function publishPhpunit(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        // Use app config for Laravel applications, library config for packages
        $isLaravelApp = file_exists($basePath . '/artisan')
            && file_exists($basePath . '/composer.json')
            && str_contains((string) file_get_contents($basePath . '/composer.json'), '"laravel/framework"');

        $stubFile = $isLaravelApp
            ? $vendorPath . '/config/phpunit-app.xml'
            : $vendorPath . '/config/phpunit.xml';

        $converted = (string) tempnam(sys_get_temp_dir(), 'phpunit');

        file_put_contents($converted, ConfigCopies::phpunitForConfig((string) file_get_contents($stubFile)));

        $published = $this->publishStub(
            basePath: $basePath,
            stubFile: $converted,
            targetFile: $basePath . '/config/dev-tools/phpunit.xml',
            force: $force,
            label: 'config/dev-tools/phpunit.xml',
            protectUserChanges: true,
        );

        PlainFile::delete($converted);

        $this->publishRootCopy(
            $basePath,
            'phpunit.xml',
            $force,
        );

        return $published;
    }

    /**
     * Generate the pint.json of config/dev-tools/, and its copy at the root, where the editor reads it.
     *
     * Pint cannot extend a file. The file of config/dev-tools/ is the complete one that the project edits: it is
     * generated from the rules of php-dev-tools and the Laravel layer of this package, and written again over the
     * changes of the project only with $force.
     */
    public function publishPint(
        string $vendorPath,
        string $basePath,
        bool $force = false,
    ): bool {
        if (is_file($basePath . '/config/dev-tools/pint.json') && ! $force) {
            $this->skip('config/dev-tools/pint.json', 'already exists (use --force to generate it again)');
        }
        else {
            $this->saveBeforeOverwriting($basePath, $basePath . '/config/dev-tools/pint.json');

            try {
                PintConfigWriter::write($basePath, [
                    $vendorPath . '/config/pint.json',
                ]);
            }
            catch (RuntimeException $runtimeException) {
                $this->output->error($runtimeException->getMessage());

                return false;
            }

            $this->output->task('Generated: config/dev-tools/pint.json');
        }

        $this->publishRootCopy(
            $basePath,
            'pint.json',
            $force,
        );

        return true;
    }

    /**
     * Publish the Rector configuration of the project into config/dev-tools/. No file at the root: no extension of an
     * editor reads it, the commands do.
     * User is expected to customize this file - protected against force overwrite.
     */
    public function publishRector(
        string $vendorPath,
        string $basePath,
        bool $force,
    ): bool {
        return $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/rector.php',
            targetFile: $basePath . '/config/dev-tools/rector.php',
            force: $force,
            label: 'config/dev-tools/rector.php',
            protectUserChanges: true,
        );
    }

    /**
     * Copy the stub file to the target location.
     */
    private function copyStub(
        string $stubFile,
        string $targetFile,
        string $label,
    ): bool {
        if (copy($stubFile, $targetFile)) {
            $this->output->task('Copied: ' . $label);

            return true;
        }

        // @codeCoverageIgnoreStart
        $this->output->error('Failed to copy: ' . $label);

        return false;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Create the given directory recursively if it does not already exist.
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir(
                $directory,
                0755,
                true,
            );
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
            && file_exists($targetFile)
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
        string $basePath,
    ): bool {
        if (! file_exists($stubFile)) {
            $this->output->warn('Stub not found, skipping: ' . $label);

            return false;
        }

        // Replace placeholder in a temporary buffer for hash comparison
        $processed = str_replace(
            'PACKAGE_GITLAB_ISSUES',
            $issuesUrl,
            (string) file_get_contents($stubFile),
        );
        $tempFile  = sys_get_temp_dir() . '/ldt_governance_' . md5($label);
        file_put_contents($tempFile, $processed);

        $this->ensureDirectoryExists(dirname($targetFile));

        try {
            // Target does not exist yet - just copy
            if (! file_exists($targetFile)) {
                copy($tempFile, $targetFile);
                $this->output->task('Copied: ' . $label);

                return true;
            }

            // Target exists, no force - compare processed content with target
            if (! $force) {
                $srcHash = hash_file('sha256', $tempFile);
                $tgtHash = hash_file('sha256', $targetFile);

                if ($srcHash === $tgtHash) {
                    // Identical - silently refresh
                    copy($tempFile, $targetFile);
                    $this->output->task('Refreshed: ' . $label);

                    return true;
                }

                // User has modified the file - protect it
                $this->skip(
                    $label,
                    'contains user modifications (use --force to overwrite)',
                    'yellow',
                );

                return false;
            }

            // Force - overwrite unconditionally, after a copy of the file
            $this->saveBeforeOverwriting($basePath, $targetFile);
            copy($tempFile, $targetFile);
            $this->output->task('Copied: ' . $label);

            return true;
        }
        finally {
            PlainFile::delete($tempFile);
        }
    }

    /**
     * The copy of a file of config/dev-tools/ at the root, for the editor. Written when the project has none, or with
     * $force; left alone otherwise (ConfigCopies tells when it differs).
     */
    private function publishRootCopy(
        string $basePath,
        string $name,
        bool $force,
    ): void {
        if (file_exists($basePath . '/' . $name) && ! $force) {
            return;
        }

        $written = ConfigCopies::sync(
            $basePath,
            [
                $name,
            ],
            fn (string $file): bool => $this->saveBeforeOverwriting($basePath, $file),
        );

        if ([] !== $written) {
            $this->output->task('Copied: ' . $name . ' (root)');
        }
    }

    /**
     * The thin file of the root, for the editor: it points at the one of config/dev-tools/.
     */
    private function publishRootThin(
        string $vendorPath,
        string $basePath,
        string $name,
        bool $force,
    ): void {
        $this->publishStub(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/root/' . $name,
            targetFile: $basePath . '/' . $name,
            force: $force,
            label: $name . ' (root)',
            protectUserChanges: false,
        );
    }

    // Core publishing logic
    // ================

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
        string $basePath,
        string $stubFile,
        string $targetFile,
        bool $force,
        string $label,
        bool $protectUserChanges,
    ): bool {
        if (! file_exists($stubFile)) {
            $this->output->warn('Stub not found, skipping: ' . $label);

            return false;
        }

        $this->ensureDirectoryExists(dirname($targetFile));

        if ($this->shouldSkip(
            $stubFile,
            $targetFile,
            $force,
            $protectUserChanges,
            $label,
        )) {
            return false;
        }

        if ($force) {
            $this->saveBeforeOverwriting($basePath, $targetFile);
        }

        return $this->copyStub(
            $stubFile,
            $targetFile,
            $label,
        );
    }

    /**
     * Derive the GitLab issues URL from the project's composer.json name.
     *
     * Convention: zairakai/laravel-dev-tools → https://gitlab.com/zairakai/php-packages/laravel-dev-tools/-/issues
     */
    private function resolveIssuesUrl(string $basePath): string
    {
        $composerPath = $basePath . '/composer.json';

        if (! file_exists($composerPath)) {
            return 'TODO: add GitLab issues URL';
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $raw      = is_array($composer)
            ? ($composer['name'] ?? '')
            : '';
        $name     = is_string($raw)
            ? $raw
            : '';

        if ('' === $name) {
            return 'TODO: add GitLab issues URL';
        }

        // Strip vendor prefix: zairakai/laravel-dev-tools → laravel-dev-tools
        $shortName = str_contains($name, '/')
            ? substr($name, strpos($name, '/') + 1)
            : $name;

        return 'https://gitlab.com/zairakai/php-packages/' . $shortName . '/-/issues';
    }

    /**
     * Keeps a copy of a file that a forced publication is about to write over, in .dev-tools-backup/ (the same folder as
     * the shell scripts use). Nothing is kept when the file does not exist.
     */
    private function saveBeforeOverwriting(string $basePath, string $file): bool
    {
        if (! file_exists($file)) {
            return false;
        }

        $this->backups[$basePath] ??= new ConfigBackup($basePath);

        if (! $this->backups[$basePath]->save($file)) {
            return false;
        }

        $this->output->task('Backed up: ' . ltrim(substr($file, strlen($basePath)), '/'));

        return true;
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
        // File exists and no force flag - skip with a notice
        if (file_exists($targetFile) && ! $force) {
            $this->skip($label, 'already exists (use --force to overwrite)');

            return true;
        }

        // File exists, force is set, but user has modified it - refuse overwrite
        if ($this->isProtectedUserFile(
            $stubFile,
            $targetFile,
            $force,
            $protectUserChanges,
        )) {
            $this->skip(
                $label,
                'contains user modifications (refusing to overwrite)',
                'red',
            );

            return true;
        }

        return false;
    }

    /**
     * Display a skipped-file message in the console.
     */
    private function skip(
        string $label,
        string $reason,
        string $color = 'yellow',
    ): void {
        $this->output->twoColumnDetail(
            'Skipped: ' . $label,
            sprintf(
                '<fg=%s>%s</>',
                $color,
                $reason,
            ),
        );
    }

    /**
     * Handle unknown publishable key.
     */
    private function unknownKey(string $key): bool
    {
        $this->output->error(sprintf(
            'Unknown publishable key "%s". Valid keys: %s',
            $key,
            implode(', ', $this->availableKeys()),
        ));

        return false;
    }
}
