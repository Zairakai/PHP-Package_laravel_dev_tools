<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools;

use Illuminate\Support\ServiceProvider;
use Zairakai\LaravelDevTools\Console\Commands\Auth\ClearActivationCommand;
use Zairakai\LaravelDevTools\Console\Commands\Dev\CleanAfterIdeHelperCommand;
use Zairakai\LaravelDevTools\Console\Commands\Dev\PublishToolingCommand;
use Zairakai\LaravelDevTools\Console\Commands\Dev\RemoveTrailingSlashesCommand;

class LaravelDevToolsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * Registers Artisan commands when running in console context.
     *
     * Note: file publishing is intentionally NOT declared via $this->publishes()
     * because the package uses its own publishing command (dev-tools:publish)
     * with granular control over groups, force protection, and user-modified file detection.
     * Using both mechanisms simultaneously would create two divergent publishing paths.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return; // @codeCoverageIgnore
        }

        // Register all Artisan commands provided by this package
        $this->commands([
            ClearActivationCommand::class,
            CleanAfterIdeHelperCommand::class,
            PublishToolingCommand::class,
            RemoveTrailingSlashesCommand::class,
        ]);
    }
}
