<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Zairakai\LaravelDevTools\Contracts\PublishOutput;

/**
 * Publishes the files of the platform from the stubs of the package into the project: the GitLab CI pipeline and the
 * governance files (CONTRIBUTING.md, CODE_OF_CONDUCT.md, SECURITY.md).
 *
 * Reached through ConfigStubPublisher, which keeps the groups and the keys.
 */
final readonly class PlatformFilesPublisher
{
    public function __construct(
        private PublishOutput $output,
        private StubWriter $writer,
    ) {}

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
        return $this->publishGitlabCiStub(
            $vendorPath . '/stubs/gitlab-ci.yml',
            $basePath,
            $force,
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
        return $this->publishGitlabCiStub(
            $vendorPath . '/stubs/gitlab-ci.fullstack.yml',
            $basePath,
            $force,
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
     * The CI file with PHP and JS: asked for, or the project uses js-dev-tools.
     */
    public function wantsFullstack(string $basePath, bool $asked = false): bool
    {
        return $asked || is_dir($basePath . '/node_modules/@zairakai/js-dev-tools');
    }

    /**
     * The name of the package: the "name" of composer.json, or an empty string.
     */
    private function packageName(string $basePath): string
    {
        $file     = $basePath . '/composer.json';
        $composer = is_file($file)
            ? json_decode((string) file_get_contents($file), true)
            : null;
        $name     = is_array($composer)
            ? ($composer['name'] ?? '')
            : '';

        return is_string($name)
            ? $name
            : '';
    }

    /**
     * The .gitlab-ci.yml of the project: the stub, with the name of the package and the cache key of composer.json.
     */
    private function publishGitlabCiStub(string $stubFile, string $basePath, bool $force): bool
    {
        if (! file_exists($stubFile)) {
            $this->output->warn('Stub not found, skipping: .gitlab-ci.yml');

            return false;
        }

        $content = (string) file_get_contents($stubFile);
        $package = $this->packageName($basePath);

        if ('' !== $package) {
            $content = str_replace(
                [
                    'PACKAGIST_PACKAGE: "vendor/my-package"',
                    'CACHE_KEY: "my-package"',
                ],
                [
                    'PACKAGIST_PACKAGE: "' . $package . '"',
                    'CACHE_KEY: "' . $this->shortName($package) . '"',
                ],
                $content,
            );
        }

        $generated = (string) tempnam(sys_get_temp_dir(), 'gitlabci');

        file_put_contents($generated, $content);

        $published = $this->writer->publish(
            basePath: $basePath,
            stubFile: $generated,
            targetFile: $basePath . '/.gitlab-ci.yml',
            force: $force,
            label: '.gitlab-ci.yml',
        );

        PlainFile::delete($generated);

        return $published;
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

        $this->writer->ensureDirectory(dirname($targetFile));

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
                $this->writer->skip(
                    $label,
                    'contains user modifications (use --force to overwrite)',
                    'yellow',
                );

                return false;
            }

            // Force - overwrite unconditionally, after a copy of the file
            $this->writer->saveBeforeOverwriting($basePath, $targetFile);
            copy($tempFile, $targetFile);
            $this->output->task('Copied: ' . $label);

            return true;
        }
        finally {
            PlainFile::delete($tempFile);
        }
    }

    /**
     * Derive the GitLab issues URL from the project's composer.json name.
     *
     * Convention: zairakai/laravel-dev-tools → https://gitlab.com/zairakai/php-packages/laravel-dev-tools/-/issues
     */
    private function resolveIssuesUrl(string $basePath): string
    {
        $name = $this->packageName($basePath);

        return '' === $name
            ? 'TODO: add GitLab issues URL'
            : 'https://gitlab.com/zairakai/php-packages/' . $this->shortName($name) . '/-/issues';
    }

    /**
     * The name of a package without its vendor: zairakai/laravel-dev-tools gives laravel-dev-tools.
     */
    private function shortName(string $package): string
    {
        return str_contains($package, '/')
            ? substr($package, (int) strpos($package, '/') + 1)
            : $package;
    }
}
