<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use RuntimeException;
use Zairakai\LaravelDevTools\Contracts\PublishOutput;

/**
 * The files that every project has from the setup, besides config/dev-tools/: the Makefile, .editorconfig, the Composer
 * scripts of the package and the block of .gitignore.
 */
final readonly class BaseFilesPublisher
{
    public function __construct(
        private StubWriter $writer,
        private PublishOutput $output,
    ) {}

    /**
     * The Composer scripts of the package, added to composer.json. With $force, a script that the project has with another
     * value is replaced, after a copy of composer.json.
     */
    public function composerScripts(string $vendorPath, string $basePath, bool $force): bool
    {
        try {
            $merged = ComposerScripts::merge(
                $basePath . '/composer.json',
                $vendorPath . '/stubs/composer-scripts.json',
                $force,
                fn (string $file): bool => $force && $this->writer->saveBeforeOverwriting($basePath, $file),
            );
        }
        catch (RuntimeException $runtimeException) {
            $this->output->warn('Composer scripts not merged: ' . $runtimeException->getMessage());

            return false;
        }

        $this->report($merged);

        return true;
    }

    public function editorConfig(string $vendorPath, string $basePath, bool $force): bool
    {
        return $this->writer->publish(
            basePath: $basePath,
            stubFile: $vendorPath . '/.editorconfig',
            targetFile: $basePath . '/.editorconfig',
            force: $force,
            label: '.editorconfig',
        );
    }

    /**
     * The block of .gitignore for the files of the tools. A project that has the block, with this title or with the one of
     * the older versions, is left as it is. A project without .gitignore is left as it is too.
     */
    public function gitignore(string $basePath): bool
    {
        $file = $basePath . '/.gitignore';

        if (! is_file($file)) {
            return false;
        }

        $text  = (string) file_get_contents($file);
        $added = GitignoreBlock::added($text);

        if (null === $added) {
            return false;
        }

        file_put_contents($file, $added);
        $this->output->task('Updated: .gitignore (dev-tools patterns)');

        return true;
    }

    /**
     * The Makefile of the project: the one for PHP, or the one for PHP and JS when the project uses js-dev-tools.
     */
    public function makefile(string $vendorPath, string $basePath, bool $force, bool $fullstack): bool
    {
        return $this->writer->publish(
            basePath: $basePath,
            stubFile: $vendorPath . '/stubs/' . (
                $fullstack || is_dir($basePath . '/node_modules/@zairakai/js-dev-tools')
                    ? 'Makefile.fullstack'
                    : 'Makefile'
            ),
            targetFile: $basePath . '/Makefile',
            force: $force,
            label: 'Makefile',
        );
    }

    /**
     * @param array{added: list<string>, updated: list<string>, skipped: list<string>} $merged
     */
    private function report(array $merged): void
    {
        if ([] !== $merged['added']) {
            $this->output->task('Added scripts: ' . implode(', ', $merged['added']));
        }

        if ([] !== $merged['updated']) {
            $this->output->task('Updated scripts (--force): ' . implode(', ', $merged['updated']));
        }

        if ([] !== $merged['skipped']) {
            $this->output->task(
                'Skipped scripts (already exist with custom values): ' . implode(', ', $merged['skipped']),
            );
        }
    }
}
