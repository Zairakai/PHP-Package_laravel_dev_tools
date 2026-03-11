<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Composer\InstalledVersions;
use Composer\IO\IOInterface;
use OutOfBoundsException;

/**
 * Synchronizes the GitLab CI pipeline ref with the installed package version.
 *
 * Reads the consumer project's .gitlab-ci.yml, detects the ref: used in the
 * laravel-dev-tools include block, and compares it against the currently
 * installed package version.
 *
 * Behavior by Composer event:
 *   - post-install-cmd → warn if out of sync (never modify files)
 *   - post-update-cmd  → auto-fix the ref if out of sync
 *
 * If .gitlab-ci.yml is absent, logs an info message and offers to create it
 * from the bundled template via artisan dev-tools:publish --publish=gitlab-ci.
 */
final class GitlabCiSynchronizer
{
    private const string CI_TEMPLATE_FILE  = '.gitlab/ci/pipeline-php-package.yml';

    private const string GITLAB_CI_FILENAME = '.gitlab-ci.yml';

    private const string GITLAB_PROJECT    = 'zairakai/php-packages/laravel-dev-tools';

    private const string PACKAGE_NAME     = 'zairakai/laravel-dev-tools';

    /**
     * Optional version resolver — injectable for testing.
     *
     * When null, resolves via InstalledVersions::getPrettyVersion().
     * In tests, inject a closure that returns a fixed version string
     * without requiring the package to be installed.
     *
     * @var (callable(): string|null)|null
     */
    private $versionResolver;

    /**
     * @param (callable(): string|null)|null $versionResolver
     */
    public function __construct(
        private readonly IOInterface $io,
        private readonly string $projectRoot,
        ?callable $versionResolver = null,
    ) {
        $this->versionResolver = $versionResolver;
    }

    /**
     * Run synchronization check.
     *
     * @param bool $autoFix True on post-update-cmd, false on post-install-cmd
     */
    public function synchronize(bool $autoFix): void
    {
        $installedVersion = $this->resolveInstalledVersion();

        if (null === $installedVersion) {
            return;
        }

        $path = $this->projectRoot . '/' . self::GITLAB_CI_FILENAME;

        if (! file_exists($path)) {
            $this->handleMissingFile();

            return;
        }

        $this->synchronizeExistingFile($path, $installedVersion, $autoFix);
    }

    /**
     * Replace the ref value in the file with the installed version.
     */
    private function applyFix(
        string $filePath,
        string $content,
        string $currentRef,
        string $installedVersion,
    ): void {
        $pattern = '/(\bref:\s*)[\'"]?' . preg_quote($currentRef, '/') . '[\'"]?/';
        $updated = preg_replace($pattern, '${1}' . $installedVersion, $content);

        // @codeCoverageIgnoreStart
        // preg_replace() returns null only on internal PCRE error — not reproducible in tests
        if (null === $updated || $updated === $content) {
            $this->warnOutOfSync($currentRef, $installedVersion);

            return;
        }

        // @codeCoverageIgnoreEnd

        // @codeCoverageIgnoreStart
        // file_put_contents() returns false only on filesystem-level failure (permissions, disk full)
        // Triggering this without vfsStream would be disproportionate for a 2-line error path
        if (false === file_put_contents($filePath, $updated)) {
            $this->io->writeError(sprintf('<e>dev-tools: Failed to write %s</e>', self::GITLAB_CI_FILENAME));

            return;
        }

        // @codeCoverageIgnoreEnd

        $this->io->write(sprintf(
            '<info>dev-tools:</info> Updated <comment>%s</comment> CI ref'
            . ' <comment>%s</comment> → <comment>%s</comment>',
            self::GITLAB_CI_FILENAME,
            $currentRef,
            $installedVersion,
        ));
    }

    // =========================================================================
    // Private — YAML parsing (line-by-line, no external dependency)
    // =========================================================================

    /**
     * Check whether the YAML content contains a laravel-dev-tools include block.
     *
     * Handles both consumer projects (include:project:) and laravel-dev-tools
     * itself (include:local: with the template file path).
     */
    private function containsDevToolsInclude(string $content): bool
    {
        return str_contains($content, self::GITLAB_PROJECT)
            || str_contains($content, self::CI_TEMPLATE_FILE);
    }

    /**
     * Extract the ref: value from the include block that references our package.
     *
     * Delegates line iteration to findRefInLines() to keep complexity low.
     * Returns null if the ref line cannot be found or parsed.
     */
    private function extractRef(string $content): ?string
    {
        return $this->findRefInLines(explode("\n", $content));
    }

    /**
     * Fetch the raw version string from the resolver or InstalledVersions.
     */
    private function fetchRawVersion(): ?string
    {
        if (null !== $this->versionResolver) {
            return ($this->versionResolver)();
        }

        // @codeCoverageIgnoreStart
        // InstalledVersions path is only reached in production (no resolver injected).
        // Tests always inject a resolver — covering this branch would require a
        // test without resolver that depends on the real installed version string,
        // making it environment-sensitive and brittle.
        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
        }
        catch (OutOfBoundsException) {
            return null;
        }

        // @codeCoverageIgnoreEnd
    }

    /**
     * Iterate lines to locate the ref: value inside the dev-tools include block.
     *
     * State machine with two flags:
     *   $inIncludeBlock — true while parsing an include: top-level block
     *   $foundProject   — true once the dev-tools project: line is seen
     *
     * Delegates guard checks to shouldSkipLine() to keep CC ≤ 5.
     *
     * @param list<string> $lines
     */
    private function findRefInLines(array $lines): ?string
    {
        $inIncludeBlock = false;
        $foundProject   = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ('include:' === $trimmed) {
                $inIncludeBlock = true;

                continue;
            }

            if ($this->shouldSkipLine($line, $trimmed, $inIncludeBlock, $foundProject)) {
                continue;
            }

            if (str_contains($trimmed, self::GITLAB_PROJECT)) {
                $foundProject = true;

                continue;
            }

            // ref: found inside our include block → parse and return
            if (str_starts_with($trimmed, 'ref:')) {
                return $this->parseRefValue($trimmed);
            }

            // New include entry started before ref: → ref is missing
            if (str_starts_with($trimmed, '- ')) {
                return null;
            }
        }

        return null;
    }

    // =========================================================================
    // Private — actions
    // =========================================================================

    /**
     * Log info when .gitlab-ci.yml is absent and offer template creation.
     *
     * The suggested command depends on the project type:
     *   - library / composer-plugin → bash setup-package.sh (no artisan)
     *   - project (Laravel app)     → php artisan dev-tools:publish
     */
    private function handleMissingFile(): void
    {
        $this->io->write(sprintf('<info>dev-tools: %s not found.</info>', self::GITLAB_CI_FILENAME));
        $this->io->write('<info>dev-tools: To use the bundled GitLab CI pipeline template, run:</info>');

        if ($this->isPackageProject()) {
            $this->io->write(
                '  <comment>bash vendor/zairakai/laravel-dev-tools/scripts/setup-package.sh'
                . ' --publish=gitlab-ci</comment>',
            );
        }
        else {
            $this->io->write('  <comment>php artisan dev-tools:publish --publish=gitlab-ci</comment>');
        }
    }

    /**
     * Compare the current ref with the installed version and act accordingly.
     *
     * Warns (install mode) or applies a fix (update mode) when out of sync.
     */
    private function handleRefSync(string $path, string $content, string $installedVersion, bool $autoFix): void
    {
        $currentRef = $this->extractRef($content);

        if (null === $currentRef) {
            $this->io->write(sprintf(
                '<warning>dev-tools: Could not parse ref: in %s — please verify manually.</warning>',
                self::GITLAB_CI_FILENAME,
            ));

            return;
        }

        if ($currentRef === $installedVersion) {
            return;
        }

        if ($autoFix) {
            $this->applyFix($path, $content, $currentRef, $installedVersion);
        }
        else {
            $this->warnOutOfSync($currentRef, $installedVersion);
        }
    }

    /**
     * Determine if the consumer project is a Composer package (library or plugin)
     * rather than a Laravel application (project type).
     *
     * Reads the project composer.json — presence of type: library or
     * type: composer-plugin indicates a package context.
     */
    private function isPackageProject(): bool
    {
        $path    = $this->projectRoot . '/composer.json';
        $content = file_exists($path) ? file_get_contents($path) : false;

        if (false === $content) {
            return false;
        }

        /** @var array<string, mixed>|null $json */
        $json = json_decode($content, true);

        // @codeCoverageIgnoreStart
        // json_decode() returns null only when JSON is invalid — composer.json is always valid JSON in practice
        if (! is_array($json)) {
            return false;
        }

        // @codeCoverageIgnoreEnd

        $type = $json['type'] ?? '';

        return in_array($type, ['library', 'composer-plugin'], true);
    }

    /**
     * Parse the ref value from a "ref: vX.Y.Z" line.
     *
     * Handles both quoted and unquoted forms:
     *   ref: v1.3.0
     *   ref: 'v1.3.0'
     *   ref: "v1.3.0"
     */
    private function parseRefValue(string $refLine): ?string
    {
        if (! preg_match('/^ref:\s*[\'"]?([^\s\'"]+)[\'"]?\s*$/', $refLine, $matches)) {
            return null;
        }

        $value = trim($matches[1]);

        return '' !== $value ? $value : null;
    }

    // =========================================================================
    // Private — version resolution
    // =========================================================================

    /**
     * Resolve the installed package version as a v-prefixed tag string.
     *
     * Returns null when the version cannot be determined — e.g. when running
     * the plugin on the package itself during development.
     */
    private function resolveInstalledVersion(): ?string
    {
        $pretty = $this->fetchRawVersion();

        if (null === $pretty) {
            return null;
        }

        return 'v' . ltrim($pretty, 'v');
    }

    /**
     * Determine whether the current line should be skipped during parsing.
     *
     * Handles two cases that both result in a continue:
     *   1. Exiting the include block (resets state flags via reference)
     *   2. Not yet inside an include block
     *
     * Using out-parameters to mutate $inIncludeBlock and $foundProject
     * avoids returning a complex value object while keeping CC low.
     */
    private function shouldSkipLine(
        string $line,
        string $trimmed,
        bool &$inIncludeBlock,
        bool &$foundProject,
    ): bool {
        if ($inIncludeBlock && '' !== $trimmed && ! str_starts_with($line, ' ') && ! str_starts_with($line, '-')) {
            $inIncludeBlock = false;
            $foundProject   = false;
        }

        return ! $inIncludeBlock;
    }

    // =========================================================================
    // Private — synchronization flow
    // =========================================================================

    /**
     * Handle synchronization for an existing .gitlab-ci.yml file.
     *
     * Reads the file and verifies it contains our CI template before
     * delegating ref comparison and fix logic to handleRefSync().
     */
    private function synchronizeExistingFile(string $path, string $installedVersion, bool $autoFix): void
    {
        $content = file_get_contents($path);

        // @codeCoverageIgnoreStart
        // file_get_contents() returns false only on OS-level failure on an already-verified existing file
        if (false === $content) {
            return;
        }

        // @codeCoverageIgnoreEnd

        if (! $this->containsDevToolsInclude($content)) {
            return;
        }

        $this->handleRefSync($path, $content, $installedVersion, $autoFix);
    }

    /**
     * Emit a warning when the ref is out of sync and auto-fix is disabled.
     */
    private function warnOutOfSync(string $currentRef, string $installedVersion): void
    {
        $this->io->write(sprintf(
            '<warning>dev-tools: %s ref <comment>%s</comment>'
            . ' does not match installed version <comment>%s</comment></warning>',
            self::GITLAB_CI_FILENAME,
            $currentRef,
            $installedVersion,
        ));

        $this->io->write('  Run <comment>composer update zairakai/laravel-dev-tools</comment> to auto-fix.');
    }
}
