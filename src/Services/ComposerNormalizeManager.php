<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Services;

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Symfony\Component\Process\Process;

/**
 * Manages ergebnis/composer-normalize installation and configuration.
 *
 * Handles checking, prompting, and installing composer-normalize
 * for consistent composer.json formatting in Laravel projects.
 */
final readonly class ComposerNormalizeManager
{
    public function __construct(
        private Command $command,
        private Factory $componentsFactory,
    ) {}

    /**
     * Prompt the user to install composer-normalize if not already installed.
     */
    public function handleComposerNormalize(string $basePath): void
    {
        if ($this->isComposerNormalizeInstalled($basePath)) {
            $this->componentsFactory->info('composer-normalize is already installed');

            return;
        }

        // @codeCoverageIgnoreStart
        if ($this->command->option('no-interaction')) {
            if ($this->command->option('with-normalize')) {
                $this->installComposerNormalize($basePath);
            }
            else {
                $this->componentsFactory->info('Skipped composer-normalize (use --with-normalize to install)');
            }

            return;
        }

        // @codeCoverageIgnoreEnd

        $this->componentsFactory->warn('composer-normalize is not installed');
        $this->command->line('  This package enables automated composer.json validation and formatting.');
        $this->command->line('  Recommended for maintaining consistent composer.json across your team.');
        $this->command->newLine();

        if ($this->command->confirm('Install ergebnis/composer-normalize now?', true)) {
            $this->installComposerNormalize($basePath);
        }
        else {
            $this->command->newLine();
            $this->command->line('Skipped. Install later with:');
            $this->command->line('  composer require --dev ergebnis/composer-normalize');
        }
    }

    /**
     * Handle composer-normalize installation based on command options.
     *
     * If in interactive mode, prompts user; otherwise installs if --with-normalize.
     */
    public function handleComposerNormalizeOption(string $basePath): void
    {
        if (! $this->command->option('no-interaction')) {
            $this->command->newLine();
            $this->handleComposerNormalize($basePath);

            return;
        }

        if ($this->command->option('with-normalize')) {
            $this->command->newLine();
            $this->installComposerNormalize($basePath);
        }
    }

    /**
     * Install composer-normalize as a development dependency via Symfony Process.
     */
    public function installComposerNormalize(string $basePath): void
    {
        $installed = false;

        $this->componentsFactory->task(
            'Installing ergebnis/composer-normalize',
            static function () use ($basePath, &$installed): void {
                $process = Process::fromShellCommandline(
                    'composer require --dev ergebnis/composer-normalize --no-interaction',
                    $basePath,
                    timeout: 300,
                );

                $process->run();

                $installed = $process->isSuccessful();
            },
        );

        if ($installed && $this->isComposerNormalizeInstalled($basePath)) {
            $this->componentsFactory->info('composer-normalize installed successfully');
        }
        else {
            $this->componentsFactory->error('Failed to install composer-normalize');
            $this->command->line('Install manually with:');
            $this->command->line('  composer require --dev ergebnis/composer-normalize');
        }
    }

    /**
     * Check whether the composer-normalize binary is present in the project vendor.
     */
    public function isComposerNormalizeInstalled(string $basePath): bool
    {
        return file_exists($basePath . '/vendor/bin/composer-normalize');
    }
}
