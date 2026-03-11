<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;

/**
 * Manages installation of git hooks into a versioned .githooks/ directory.
 *
 * Strategy: hooks are copied (not symlinked) into .githooks/ at the project root.
 * Git is configured via `git config core.hooksPath .githooks` so the hooks
 * are active immediately without any per-developer action.
 *
 * Advantages of .githooks/ over .git/hooks/:
 *   - Versionable: hooks are committed with the project
 *   - Inspectable and editable directly in the project tree
 *   - Shared automatically with all team members on clone
 */
final readonly class GitHooksManager
{
    /**
     * Hooks to install, in the order they are executed by git.
     */
    private const array HOOKS = [
        'commit-msg',
        'pre-commit',
        'pre-push',
        'prepare-commit-msg',
    ];

    public function __construct(
        private Command $command,
        private Factory $componentsFactory,
    ) {
        // Promoted properties are assigned by PHP automatically.
    }

    /**
     * Copy git hooks stubs into .githooks/ and configure git to use them.
     *
     * @param string $stubsPath Absolute path to the vendor stubs directory
     * @param string $basePath  Absolute path to the project root
     * @param bool   $force     Overwrite existing hooks if true
     * @param bool   $withHooks Whether hooks should actually be installed
     */
    public function publishGitHooks(
        string $stubsPath,
        string $basePath,
        bool $force,
        bool $withHooks,
    ): void {
        if (! $withHooks) {
            return; // @codeCoverageIgnore
        }

        if (! $this->ensureGitRepository($basePath)) {
            return;
        }

        $stubHooksDir    = $stubsPath . '/githooks';
        $projectHooksDir = $basePath . '/.githooks';

        $this->componentsFactory->info('Installing git hooks into .githooks/…');
        $this->ensureHooksDirectoryExists($projectHooksDir);

        $installed = $this->copyHooks($stubHooksDir, $projectHooksDir, $force);

        if (0 < $installed) {
            $this->configureGitHooksPath($basePath);
        }

        $this->command->newLine();
        $this->componentsFactory->info(sprintf('Installed %d git hook(s) into .githooks/', $installed));
    }

    /**
     * Configure git to use .githooks/ as the hooks directory.
     *
     * Executes `git config core.hooksPath .githooks` in the project directory.
     * Requires a real git binary and an initialized repository.
     *
     * Success and failure are both reported via the console components factory.
     */
    private function configureGitHooksPath(string $basePath): void
    {
        $cwd = getcwd();
        chdir($basePath);
        exec('git config core.hooksPath .githooks', $output, $returnCode);
        unset($output);

        if (false !== $cwd) {
            chdir($cwd);
        }

        if (0 === $returnCode) {
            $this->componentsFactory->task('Configured: git config core.hooksPath .githooks');
        }
        else {
            // @codeCoverageIgnoreStart
            // git config failure — only when git binary unavailable or repository corrupted.
            $this->componentsFactory->warn('Could not set git config core.hooksPath — run manually:');
            $this->command->line('  <fg=cyan>git config core.hooksPath .githooks</>');
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Copy a single hook file from stubs to .githooks/.
     *
     * @return bool True if the hook was installed or overwritten
     */
    private function copyHook(
        string $stubHooksDir,
        string $projectHooksDir,
        string $hook,
        bool $force,
    ): bool {
        $source      = $stubHooksDir . '/' . $hook;
        $destination = $projectHooksDir . '/' . $hook;

        if (! File::exists($source)) {
            // @codeCoverageIgnoreStart
            $this->componentsFactory->warn('Hook stub not found: ' . $hook);

            return false;
            // @codeCoverageIgnoreEnd
        }

        if (File::exists($destination) && ! $force) {
            $this->componentsFactory->twoColumnDetail(
                'Skipped: .githooks/' . $hook,
                '<fg=yellow>already exists (use --force to overwrite)</>',
            );

            return false;
        }

        File::copy($source, $destination);
        chmod($destination, 0755);
        $this->componentsFactory->task('Copied: .githooks/' . $hook);

        return true;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Copy all hook stubs from the vendor directory to .githooks/.
     *
     * @return int Number of hooks successfully installed
     */
    private function copyHooks(string $stubHooksDir, string $projectHooksDir, bool $force): int
    {
        $installed = 0;

        foreach (self::HOOKS as $hook) {
            if ($this->copyHook($stubHooksDir, $projectHooksDir, $hook, $force)) {
                $installed++;
            }
        }

        return $installed;
    }

    /**
     * Verify that the directory is a git repository.
     */
    private function ensureGitRepository(string $basePath): bool
    {
        if (File::isDirectory($basePath . '/.git')) {
            return true;
        }

        $this->componentsFactory->warn('Not a git repository — skipping git hooks installation.');

        return false;
    }

    private function ensureHooksDirectoryExists(string $projectHooksDir): void
    {
        if (! File::isDirectory($projectHooksDir)) {
            File::makeDirectory($projectHooksDir, 0755, true);
            $this->componentsFactory->task('Created: .githooks/');
        }
    }
}
