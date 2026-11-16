<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Zairakai\LaravelDevTools\Contracts\PublishOutput;

/**
 * Writes a file of the package in a project: a file that equals the stub is refreshed, one that differs is left alone,
 * and --force replaces it after a copy in .dev-tools-backup/.
 */
final class StubWriter
{
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
     * Create the given directory recursively if it does not already exist.
     */
    public function ensureDirectory(string $directory): void
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
     * Core workflow for copying a stub file to its target location.
     *
     * @param string $stubFile   Absolute path to the stub file
     * @param string $targetFile Absolute path to the target file
     * @param bool   $force      Whether to overwrite an existing file
     * @param string $label      Human-readable label for console output
     */
    public function publish(
        string $basePath,
        string $stubFile,
        string $targetFile,
        bool $force,
        string $label,
    ): bool {
        if (! file_exists($stubFile)) {
            $this->output->warn('Stub not found, skipping: ' . $label);

            return false;
        }

        $this->ensureDirectory(dirname($targetFile));

        if ($this->shouldSkip(
            $stubFile,
            $targetFile,
            $force,
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
     * Keeps a copy of a file that a forced publication is about to write over, in .dev-tools-backup/ (the same folder as
     * the shell scripts use). Nothing is kept when the file does not exist.
     */
    public function saveBeforeOverwriting(string $basePath, string $file): bool
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
     * Display a skipped-file message in the console.
     */
    public function skip(
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
     * What to do with a target that already exists, without --force: a file that equals the stub is refreshed (nothing
     * changes), a file that differs is the project's and is left alone. With --force the file is replaced, after a copy
     * in .dev-tools-backup/ (the caller does it).
     */
    private function shouldSkip(
        string $stubFile,
        string $targetFile,
        bool $force,
        string $label,
    ): bool {
        if (! file_exists($targetFile) || $force) {
            return false;
        }

        if (hash_file('sha256', $stubFile) === hash_file('sha256', $targetFile)) {
            return false;
        }

        $this->skip(
            $label,
            'contains user modifications (use --force to overwrite)',
            'yellow',
        );

        return true;
    }
}
