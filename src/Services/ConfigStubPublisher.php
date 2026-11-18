<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

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
 * The public methods are the entry points of the publication, one per publishable asset. The work is done by three
 * classes: BaseFilesPublisher (Makefile, .editorconfig, .gitignore, Composer scripts), ToolConfigPublisher (the
 * configuration of the tools) and PlatformFilesPublisher (GitLab CI and governance files).
 */
final readonly class ConfigStubPublisher
{
    /**
     * Maps group names to their list of publishable keys.
     *
     * @var array<string, list<string>>
     */
    private const array GROUPS = [
        'setup'      => [
            'makefile',
            'editorconfig',
            'baseline',
            'phpstan',
            'gitignore',
            'composer-scripts',
        ],
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

    private BaseFilesPublisher $base;

    private PlatformFilesPublisher $platform;

    private ToolConfigPublisher $tools;

    public function __construct(
        private PublishOutput $output,
    ) {
        $writer         = new StubWriter($output);
        $this->base     = new BaseFilesPublisher($writer, $output);
        $this->tools    = new ToolConfigPublisher($output, $writer);
        $this->platform = new PlatformFilesPublisher($output, $writer);
    }

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
     * See ToolConfigPublisher::publishBaseline().
     */
    public function publishBaseline(string $vendorPath, string $basePath): bool
    {
        return $this->tools->publishBaseline($vendorPath, $basePath);
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
        bool $fullstack = false,
    ): bool {
        // The baseline is never forced: its method takes no $force, the call ignores it
        $publish = match ($key) {
            'phpstan'            => $this->publishPhpstan(...),
            'rector'             => $this->publishRector(...),
            'insights'           => $this->publishInsights(...),
            'baseline'           => $this->publishBaseline(...),
            'pint'               => $this->publishPint(...),
            'markdownlint'       => $this->publishMarkdownlint(...),
            'markdownlintignore' => $this->publishMarkdownlintIgnore(...),
            'phpunit'            => $this->publishPhpunit(...),
            'hooks'              => $this->publishHooks(...),
            'gitlab-ci'          => $this->publishGitlabCi(...),
            'governance'         => $this->publishGovernanceFiles(...),
            'makefile'           => $this->base->makefile(...),
            'editorconfig'       => $this->base->editorConfig(...),
            'composer-scripts'   => $this->base->composerScripts(...),
            'gitignore'          => fn (string $vendor, string $base): bool => $this->base->gitignore($base),
            default              => null,
        };

        return null === $publish
            ? $this->unknownKey($key)
            : $publish($vendorPath, $basePath, $force, $fullstack);
    }

    // Public API - individual file publishers
    // ================

    /**
     * See ToolConfigPublisher::publishEnlightn().
     */
    public function publishEnlightn(string $vendorPath, string $basePath, bool $force): void
    {
        $this->tools->publishEnlightn($vendorPath, $basePath, $force);
    }

    /**
     * See PlatformFilesPublisher::publishGitlabCi().
     */
    public function publishGitlabCi(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->platform->publishGitlabCi($vendorPath, $basePath, $force);
    }

    /**
     * See PlatformFilesPublisher::publishGitlabCiFullstack().
     */
    public function publishGitlabCiFullstack(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->platform->publishGitlabCiFullstack($vendorPath, $basePath, $force);
    }

    /**
     * See PlatformFilesPublisher::publishGovernanceFiles().
     */
    public function publishGovernanceFiles(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->platform->publishGovernanceFiles($vendorPath, $basePath, $force);
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
        bool $fullstack = false,
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
                $fullstack,
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
     * See ToolConfigPublisher::publishInsights().
     */
    public function publishInsights(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->tools->publishInsights($vendorPath, $basePath, $force);
    }

    /**
     * See ToolConfigPublisher::publishMarkdownlint().
     */
    public function publishMarkdownlint(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->tools->publishMarkdownlint($vendorPath, $basePath, $force);
    }

    /**
     * See ToolConfigPublisher::publishMarkdownlintIgnore().
     */
    public function publishMarkdownlintIgnore(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->tools->publishMarkdownlintIgnore($vendorPath, $basePath, $force);
    }

    // Individual publishers
    // ================

    /**
     * See ToolConfigPublisher::publishPhpstan().
     */
    public function publishPhpstan(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->tools->publishPhpstan($vendorPath, $basePath, $force);
    }

    /**
     * See ToolConfigPublisher::publishPhpunit().
     */
    public function publishPhpunit(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->tools->publishPhpunit($vendorPath, $basePath, $force);
    }

    /**
     * See ToolConfigPublisher::publishPint().
     */
    public function publishPint(string $vendorPath, string $basePath, bool $force = false): bool
    {
        return $this->tools->publishPint($vendorPath, $basePath, $force);
    }

    /**
     * See ToolConfigPublisher::publishRector().
     */
    public function publishRector(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->tools->publishRector($vendorPath, $basePath, $force);
    }

    /**
     * Publish a group or a key, as `--publish=<target>` says. The one entry of the two commands (Artisan and the shell
     * setup).
     *
     * @param string $target    A group, or a key (`phpstan-baseline` is the old name of `baseline`)
     * @param bool   $fullstack Publish the CI file of a project with PHP and JS
     *
     * @return bool false for an unknown target (a file left alone is not an error)
     */
    public function publishTarget(
        string $target,
        string $vendorPath,
        string $basePath,
        bool $force,
        bool $fullstack = false,
    ): bool {
        $target = 'phpstan-baseline' === $target
            ? 'baseline'
            : $target;

        if (
            'gitlab-ci' === $target
            && $this->wantsFullstack($basePath, $fullstack)
        ) {
            $this->publishGitlabCiFullstack($vendorPath, $basePath, $force);

            return true;
        }

        if (in_array($target, $this->availableGroups(), true)) {
            return $this->publishGroup($target, $vendorPath, $basePath, $force, $fullstack);
        }

        if (in_array($target, $this->availableKeys(), true)) {
            // A file that is left alone (the project's own) is not an error: the output says it
            $this->publishByKey($target, $vendorPath, $basePath, $force, $fullstack);

            return true;
        }

        $this->output->error(sprintf('Unknown publish target: "%s"', $target));
        $this->output->twoColumnDetail('Valid groups', implode(', ', $this->availableGroups()));
        $this->output->twoColumnDetail('Valid keys', implode(', ', $this->availableKeys()));

        return false;
    }

    /**
     * See PlatformFilesPublisher::wantsFullstack().
     */
    public function wantsFullstack(string $basePath, bool $asked = false): bool
    {
        return $this->platform->wantsFullstack($basePath, $asked);
    }

    // Core publishing logic
    // ================

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
