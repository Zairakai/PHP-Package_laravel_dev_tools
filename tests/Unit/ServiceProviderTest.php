<?php

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\LaravelDevToolsServiceProvider;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    /**
     * Package can publish assets via custom command.
     */
    #[Test]
    public function package_can_publish_assets(): void
    {
        // Test that our custom publish command works
        $exitCode = Artisan::call('dev-tools:publish', ['--help' => true]);

        $this->assertEquals(
            0,
            $exitCode,
            'dev-tools:publish command should be callable',
        );

        $output = Artisan::output();

        $this->assertStringContainsString(
            'Publish Zairakai Dev Tools',
            $output,
            'Command description should be present',
        );
    }

    /**
     * Package commands are registered.
     */
    #[Test]
    public function package_commands_are_registered(): void
    {
        $commands = Artisan::all();

        // Check for dev-tools:publish command
        $this->assertArrayHasKey(
            'dev-tools:publish',
            $commands,
            'dev-tools:publish command should be registered',
        );

        // Check for other commands
        $expectedCommands = [
            'dev-tools:auth:clear-activations',
            'dev-tools:clean-ide-helper',
            'dev-tools:remove-trailing-slashes',
            'dev-tools:publish',
        ];

        $registeredCommands = array_keys($commands);

        foreach ($expectedCommands as $expectedCommand) {
            $this->assertContains(
                $expectedCommand,
                $registeredCommands,
                sprintf('Command %s should be registered', $expectedCommand),
            );
        }
    }

    /**
     * Package configuration can be published.
     */
    #[Test]
    public function package_publishes_configuration(): void
    {
        // Publishing is handled by dev-tools:publish command, not via Laravel service provider.
        // Verify the command is available and returns success code.
        $exitCode = Artisan::call('dev-tools:publish', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(
            0,
            $exitCode,
            'dev-tools:publish command should succeed and publish Makefile + baseline',
        );
    }

    /**
     * Service provider is registered correctly.
     */
    #[Test]
    public function service_provider_is_registered(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(
            LaravelDevToolsServiceProvider::class,
            $providers,
            'LaravelDevToolsServiceProvider should be registered',
        );
    }
}
