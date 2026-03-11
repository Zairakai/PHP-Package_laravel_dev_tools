<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Console\Commands\Dev;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Zairakai\LaravelDevTools\Traits\RemoveTrailingSlashes;

/**
 * Command to remove trailing slashes from self-closing HTML tags in Blade files.
 *
 * Supports dry-run mode and optional path argument.
 */
final class RemoveTrailingSlashesCommand extends Command
{
    use RemoveTrailingSlashes;

    protected $description = 'Remove trailing slashes from self-closing HTML tags in .blade.php files';

    protected $signature = 'dev-tools:remove-trailing-slashes
                            {path? : Path to scan (defaults to resources/views)}
                            {--dry-run : Show what would be changed without making changes}';

    /**
     * Invoke the command.
     * Acts as a callable alias for handle().
     *
     * @codeCoverageIgnore Simple proxy method - tested via handle()
     */
    public function __invoke(): void
    {
        $this->handle();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $path   = $this->getTargetPath();
        $dryRun = (bool) $this->option('dry-run');

        if (! $this->validateDirectory($path)) {
            return;
        }

        $finder = $this->createBladeFileFinder($path);

        $this->displayScanningMessage($dryRun);

        [$filesCount, $modifiedCount] = $this->processBladeFiles($finder, $dryRun);

        $this->displaySummary($filesCount, $modifiedCount, $dryRun);
    }

    /**
     * Create a Finder instance to locate Blade files.
     */
    private function createBladeFileFinder(string $path): Finder
    {
        $finder = new Finder;
        $finder->files()
            ->name('*.blade.php')
            ->in($path);

        return $finder;
    }

    /**
     * Display scanning message.
     */
    private function displayScanningMessage(bool $dryRun): void
    {
        $this->info('Scanning for trailing slashes in Blade files…');

        if ($dryRun) {
            $this->warn('DRY RUN: No files will be modified');
        }
    }

    /**
     * Display summary after processing files.
     */
    private function displaySummary(int $filesCount, int $modifiedCount, bool $dryRun): void
    {
        $action = $dryRun ? 'Would fix' : 'Fixed';
        $this->info(sprintf('%s %d of %d files.', $action, $modifiedCount, $filesCount));
    }

    /**
     * Determine target path from argument or default to resources path.
     */
    private function getTargetPath(): string
    {
        $pathArg = $this->argument('path');

        return is_string($pathArg) ? $pathArg : $this->laravel->resourcePath();
    }

    /**
     * Process a single Blade file.
     *
     * @return bool True if file content was modified
     */
    private function processBladeFile(SplFileInfo $file, bool $dryRun): bool
    {
        $filePath        = $file->getRealPath();
        $content         = File::get($filePath);
        $modifiedContent = $this->processAutoClosingTags($content);

        if ($modifiedContent === $content) {
            return false;
        }

        $relativePath = str_replace($this->laravel->basePath() . '/', '', $filePath);

        if ($dryRun) {
            $this->line('Would modify: ' . $relativePath);
        }
        else {
            File::put($filePath, $modifiedContent);
            $this->line('Modified: ' . $relativePath);
        }

        return true;
    }

    /**
     * Process all Blade files.
     *
     * @return array{int, int} [filesCount, modifiedCount]
     */
    private function processBladeFiles(Finder $finder, bool $dryRun): array
    {
        $filesCount    = 0;
        $modifiedCount = 0;

        foreach ($finder as $file) {
            $filesCount++;

            if ($this->processBladeFile($file, $dryRun)) {
                $modifiedCount++;
            }
        }

        return [$filesCount, $modifiedCount];
    }

    /**
     * Validate that the target directory exists.
     */
    private function validateDirectory(string $path): bool
    {
        if (File::isDirectory($path)) {
            return true;
        }

        $this->error(sprintf("Directory '%s' does not exist.", $path));

        return false;
    }
}
