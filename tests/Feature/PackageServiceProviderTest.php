<?php

namespace Zairakai\LaravelDevTools\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\LaravelDevToolsServiceProvider;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class PackageServiceProviderTest extends TestCase
{
    /**
     * Commands are registered in console environment.
     */
    #[Test]
    public function commands_registered_in_console_environment(): void
    {
        // Simulate console environment
        $this->assertTrue($this->app->runningInConsole());

        $commands = Artisan::all();

        // Verify package commands exist
        $packageCommands = array_filter(array_keys($commands), fn (int|string $command): bool => str_starts_with((string) $command, 'dev-tools:')
            || str_starts_with((string) $command, 'dev:')
            || str_starts_with((string) $command, 'auth:clear-activation'));

        $this->assertNotEmpty(
            $packageCommands,
            'Package should register commands in console environment',
        );

        $this->assertGreaterThanOrEqual(
            4,
            count($packageCommands),
            'Package should register at least 4 commands',
        );
    }

    /**
     * Service provider boots correctly.
     */
    #[Test]
    public function service_provider_boots_successfully(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(
            LaravelDevToolsServiceProvider::class,
            $providers,
            'Service provider should be loaded',
        );

        $this->assertTrue(
            $providers[LaravelDevToolsServiceProvider::class],
            'Service provider should be booted',
        );
    }
}
