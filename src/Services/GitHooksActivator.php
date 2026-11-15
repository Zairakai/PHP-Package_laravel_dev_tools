<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;

/**
 * Activates the git hooks of zairakai/laravel-dev-tools.
 *
 * The hooks are the same for every PHP project of zairakai: git reads them in place through
 * `git config core.hooksPath vendor/zairakai/laravel-dev-tools/stubs/githooks`, nothing is copied into the project.
 * They run the Composer scripts of the project: `quality:fast` before a commit, `quality` before a push.
 */
final readonly class GitHooksActivator
{
    /**
     * The hooks directory, relative to the root of the project.
     */
    public const string HOOKS_PATH = 'vendor/zairakai/laravel-dev-tools/stubs/githooks';

    public function __construct(private Factory $componentsFactory)
    {
        // Promoted properties are assigned by PHP automatically.
    }

    /**
     * Point `core.hooksPath` of the project at the hooks of laravel-dev-tools.
     *
     * @param string $basePath Absolute path to the project root
     *
     * @return bool True when git is configured
     */
    public function activate(string $basePath): bool
    {
        if (! File::isDirectory($basePath . '/.git')) {
            $this->componentsFactory->warn('Not a git repository - skipping git hooks installation.');

            return false;
        }

        // A `.git` directory that is not a repository would send git up to a parent repository: configure only the project.
        exec(sprintf('git -C %s rev-parse --show-toplevel 2>/dev/null', escapeshellarg($basePath)), $toplevel);

        if (realpath($toplevel[0] ?? '') !== realpath($basePath)) {
            $this->componentsFactory->warn('Not a git repository - skipping git hooks installation.');

            return false;
        }

        if (! File::isDirectory($this->installedHooksDirectory())) {
            $this->componentsFactory->warn('Hooks of laravel-dev-tools not found - run: composer install');

            return false;
        }

        exec(
            sprintf(
                'git -C %s config core.hooksPath %s 2>&1',
                escapeshellarg($basePath),
                escapeshellarg(self::HOOKS_PATH),
            ),
            $output,
            $returnCode,
        );
        unset($output);

        if (0 === $returnCode) {
            $this->componentsFactory->task('Configured: git config core.hooksPath ' . self::HOOKS_PATH);

            return true;
        }

        $this->componentsFactory->warn('Could not set git config core.hooksPath - run manually:');
        $this->componentsFactory->twoColumnDetail('git config core.hooksPath ' . self::HOOKS_PATH, '');

        return false;
    }

    /**
     * The hooks directory of the installed laravel-dev-tools.
     */
    private function installedHooksDirectory(): string
    {
        // src/Services/GitHooksActivator.php: the package root is three levels up.
        return dirname(__DIR__, 2) . '/stubs/githooks';
    }
}
