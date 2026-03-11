<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Console\Commands\Dev;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Zairakai\LaravelDevTools\Services\ComposerNormalizeManager;
use Zairakai\LaravelDevTools\Services\ConfigStubPublisher;
use Zairakai\LaravelDevTools\Services\GitHooksManager;

/**
 * Publishes Zairakai Dev Tools configuration into the project.
 *
 * Default behavior (no options):
 *   - Copies Makefile to project root
 *   - Copies .editorconfig to project root
 *   - Copies config/dev-tools/baseline.neon (empty baseline, skipped if exists)
 *
 * Publishing on demand:
 *   --publish          → publish all groups (quality, style, testing, hooks)
 *   --publish=quality  → publish quality group (phpstan, rector, insights, baseline)
 *   --publish=style    → publish style group (pint, markdownlint)
 *   --publish=testing  → publish testing group (phpunit)
 *   --publish=hooks    → publish git hooks to .githooks/
 *   --publish=pint     → publish a single config file by key
 *
 * Git hooks:
 *   --with-hooks       → install git hooks into .githooks/ and configure git
 */
final class PublishToolingCommand extends Command
{
    protected $description = 'Publish Zairakai Dev Tools configuration';

    protected $signature = 'dev-tools:publish
                            {--force : Overwrite existing files (respects user-modified file protection)}
                            {--publish= : Publish configs — omit value for all, or pass group/key name}
                            {--with-hooks : Install git hooks into .githooks/ and configure git}
                            {--with-normalize : Install ergebnis/composer-normalize}
                            {--fullstack : Force full-stack Makefile + CI stub (PHP + JS)}';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $basePath   = $this->laravel->basePath();
        $vendorPath = dirname(__DIR__, 4);
        $force      = (bool) $this->option('force');
        $fullstack  = (bool) $this->option('fullstack');

        $configStubPublisher          = new ConfigStubPublisher($this->components);
        $gitHooksManager              = new GitHooksManager($this, $this->components);
        $composerNormalizeManager     = new ComposerNormalizeManager($this, $this->components);

        $this->components->info('Publishing zairakai/laravel-dev-tools configuration…');
        $this->newLine();

        // =====================================================================
        // --publish mode: publish configs on demand
        // =====================================================================

        if ($this->hasOption('publish') && null !== $this->option('publish')) {
            return $this->handlePublish($configStubPublisher, $vendorPath, $basePath, $force, $fullstack);
        }

        // =====================================================================
        // Default setup: Makefile + .editorconfig + baseline only
        // =====================================================================

        $this->publishMakefile($basePath, $vendorPath, $force, $fullstack);
        $this->publishEditorConfig($basePath, $vendorPath, $force);

        // Baseline is always created if missing (never force-overwritten)
        $configStubPublisher->publishBaseline($vendorPath, $basePath);

        // =====================================================================
        // Optional: git hooks
        // =====================================================================

        if ((bool) $this->option('with-hooks')) {
            $this->newLine();
            $gitHooksManager->publishGitHooks($vendorPath . '/stubs', $basePath, $force, true);
        }

        // =====================================================================
        // Optional: composer-normalize
        // =====================================================================

        $composerNormalizeManager->handleComposerNormalizeOption($basePath);

        $this->displaySuccessMessage($basePath, (bool) $this->option('with-hooks'));

        return self::SUCCESS;
    }

    /**
     * Display git hooks installation details.
     */
    private function displayGitHooksInfo(): void
    {
        $this->newLine();
        $this->line('<fg=yellow>Git hooks installed (versioned in .githooks/):</>');
        $this->line('  • <fg=cyan>pre-commit</>        - Code style check (staged files only)');
        $this->line('  • <fg=cyan>commit-msg</>        - Conventional commit format validation');
        $this->line('  • <fg=cyan>pre-push</>          - PHPStan + Pint (full quality gate)');
        $this->line('  • <fg=cyan>prepare-commit-msg</> - Auto-prefix ticket from branch');
        $this->newLine();
        $this->line('<fg=yellow>To disable a hook:</> Remove or rename the file in .githooks/');
        $this->line('  <fg=gray># Example: mv .githooks/pre-push .githooks/pre-push.disabled</>');
    }

    /**
     * Display available Make targets in the console.
     */
    private function displayMakeTargets(string $basePath): void
    {
        $this->components->info('Available make targets (run <fg=cyan>make help</> for full list):');
        $this->newLine();

        $this->line('  <fg=yellow>Code Style & Quality:</>');
        $this->line('  <fg=green>make pint</>         - Check code style');
        $this->line('  <fg=green>make pint-fix</>     - Fix code style');
        $this->line('  <fg=green>make phpstan</>      - Run static analysis');
        $this->line('  <fg=green>make rector</>       - Check code modernization');
        $this->line('  <fg=green>make insights</>     - Run quality analysis');
        $this->line('  <fg=green>make phpmetrics</>   - Generate code metrics');

        $this->newLine();
        $this->line('  <fg=yellow>Aggregated:</>');
        $this->line('  <fg=green>make quality</>      - Run all quality checks');
        $this->line('  <fg=green>make quality-fix</>  - Auto-fix all issues');

        if ($this->isComposerNormalizeInstalled($basePath)) {
            // @codeCoverageIgnoreStart
            $this->line('  <fg=green>make composer-normalize</>     - Validate composer.json');
            // @codeCoverageIgnoreEnd
            $this->line('  <fg=green>make composer-normalize-fix</> - Fix composer.json');
        }

        $this->line('  <fg=green>make git-update</>   - Update all local branches');
        $this->line('  <fg=green>make git-cleanup</>  - Remove obsolete branches');
    }

    // =========================================================================
    // Output helpers
    // =========================================================================

    /**
     * Display the final success message with next steps.
     */
    private function displaySuccessMessage(string $basePath, bool $withHooks): void
    {
        $this->newLine();
        $this->components->info('Configuration published successfully!');
        $this->newLine();

        $this->displayMakeTargets($basePath);

        if ($withHooks) {
            $this->displayGitHooksInfo();
        }
        else {
            $this->newLine();
            $this->line('<fg=yellow>Tip:</> Run with <fg=cyan>--with-hooks</> to install'
                . ' quality-enforcing git hooks');
            $this->line('<fg=yellow>Tip:</> Run with <fg=cyan>--publish</> to publish all'
                . ' tool configurations');
            $this->line('       or <fg=cyan>--publish=quality</>, <fg=cyan>--publish=style</>'
                . ', <fg=cyan>--publish=pint</>…');
        }
    }

    // =========================================================================
    // --publish handler
    // =========================================================================

    /**
     * Handle the --publish option.
     *
     * Accepts a group name (quality, style, testing, hooks, all),
     * a single key name (pint, phpstan…), or empty string for 'all'.
     */
    private function handlePublish(
        ConfigStubPublisher $configStubPublisher,
        string $vendorPath,
        string $basePath,
        bool $force,
        bool $fullstack,
    ): int {
        // Empty value means --publish without argument → publish all
        $rawTarget = $this->option('publish');
        $target    = ! is_string($rawTarget) || '' === $rawTarget
            ? 'all'
            : $rawTarget;

        $this->components->info(sprintf('Publishing group/key: <fg=cyan>%s</>', $target));
        $this->newLine();

        $availableGroups = $configStubPublisher->availableGroups();
        $availableKeys   = $configStubPublisher->availableKeys();

        if ('gitlab-ci' === $target && ($fullstack || file_exists($basePath . '/package.json'))) {
            $configStubPublisher->publishGitlabCiFullstack($vendorPath, $basePath, $force);
        }
        // Attempt to publish as group first, then as individual key
        elseif (in_array($target, $availableGroups, true)) {
            $configStubPublisher->publishGroup($target, $vendorPath, $basePath, $force);
        }
        elseif (in_array($target, $availableKeys, true)) {
            $configStubPublisher->publishByKey($target, $vendorPath, $basePath, $force);
        }
        else {
            $this->components->error(sprintf('Unknown publish target: "%s"', $target));
            $this->newLine();
            $this->line(sprintf('  <fg=yellow>Valid groups:</> %s', implode(', ', $availableGroups)));
            $this->line(sprintf('  <fg=yellow>Valid keys:</>   %s', implode(', ', $availableKeys)));

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Done. Edit published files in <fg=cyan>config/dev-tools/</>');

        return self::SUCCESS;
    }

    /**
     * Check whether the composer-normalize binary is present in the project vendor.
     */
    private function isComposerNormalizeInstalled(string $basePath): bool
    {
        return file_exists($basePath . '/vendor/bin/composer-normalize');
    }

    /**
     * Copy the .editorconfig stub into the project root.
     */
    private function publishEditorConfig(string $basePath, string $vendorPath, bool $force): void
    {
        $targetFile = $basePath . '/.editorconfig';
        $stubFile   = $vendorPath . '/.editorconfig';

        if (! File::exists($stubFile)) {
            // @codeCoverageIgnoreStart
            // .editorconfig is optional — no error if missing from package.
            return;
            // @codeCoverageIgnoreEnd
        }

        if (File::exists($targetFile) && ! $force) {
            $this->components->twoColumnDetail(
                'Skipped: .editorconfig',
                '<fg=yellow>already exists (use --force to overwrite)</>',
            );

            return;
        }

        File::copy($stubFile, $targetFile);
        $this->components->task('Copied: .editorconfig');
    }

    // =========================================================================
    // Default setup file publishers
    // =========================================================================

    /**
     * Copy the Makefile stub into the project root.
     */
    private function publishMakefile(string $basePath, string $vendorPath, bool $force, bool $fullstack): void
    {
        $targetFile   = $basePath . '/Makefile';
        $stubFile     = $vendorPath . '/stubs/Makefile.stub';
        $useFullstack = $fullstack || file_exists($basePath . '/package.json');

        if ($useFullstack) {
            $stubFile = $vendorPath . '/stubs/Makefile.fullstack.stub';
        }

        if (! File::exists($stubFile)) {
            // @codeCoverageIgnoreStart
            // Makefile stub is always packaged with the vendor — this path is not reachable in practice.
            $this->components->error('Makefile stub not found.');

            return;
            // @codeCoverageIgnoreEnd
        }

        if (File::exists($targetFile) && ! $force) {
            $this->components->twoColumnDetail(
                'Skipped: Makefile',
                '<fg=yellow>already exists (use --force to overwrite)</>',
            );

            return;
        }

        File::copy($stubFile, $targetFile);
        $this->components->task('Copied: Makefile');
    }
}
