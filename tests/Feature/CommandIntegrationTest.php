<?php

namespace Zairakai\LaravelDevTools\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\LaravelDevToolsServiceProvider;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class CommandIntegrationTest extends TestCase
{
    /**
     * All package commands are registered and accessible.
     */
    #[Test]
    public function all_package_commands_are_registered(): void
    {
        $expectedCommands = [
            'dev-tools:auth:clear-activations',
            'dev-tools:clean-ide-helper',
            'dev-tools:remove-trailing-slashes',
            'dev-tools:publish',
        ];

        $registeredCommands = array_keys(Artisan::all());

        foreach ($expectedCommands as $expectedCommand) {
            $this->assertContains(
                $expectedCommand,
                $registeredCommands,
                sprintf('Command %s should be registered', $expectedCommand),
            );
        }
    }

    /**
     * Command output formatting.
     */
    #[Test]
    public function command_output_is_well_formatted(): void
    {
        $exitCode = Artisan::call('dev-tools:publish', ['--help' => true]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();

        // Should have description
        $this->assertStringContainsString('Publish', $output);

        // Should list options
        $this->assertStringContainsString('--force', $output);
        $this->assertStringContainsString('--with-hooks', $output);
    }

    /**
     * Commands respond to --help flag.
     */
    #[Test]
    public function commands_respond_to_help_flag(): void
    {
        $commands = [
            'dev-tools:auth:clear-activations',
            'dev-tools:clean-ide-helper',
            'dev-tools:remove-trailing-slashes',
            'dev-tools:publish',
        ];

        foreach ($commands as $command) {
            $exitCode = Artisan::call($command, ['--help' => true]);

            $this->assertEquals(
                0,
                $exitCode,
                sprintf('Command %s should respond to --help', $command),
            );

            $output = Artisan::output();
            $this->assertNotEmpty($output, sprintf('Command %s should produce help output', $command));
        }
    }

    /**
     * Package works in different Laravel environments.
     */
    #[Test]
    public function package_works_in_testing_environment(): void
    {
        // Verify we're in testing environment
        $this->assertEquals('testing', $this->app->environment());

        // Commands should still work
        $exitCode = Artisan::call('dev-tools:publish', ['--help' => true]);
        $this->assertEquals(0, $exitCode);

        // Service provider should be loaded
        $providers = $this->app->getLoadedProviders();
        $this->assertArrayHasKey(
            LaravelDevToolsServiceProvider::class,
            $providers,
        );
    }
}
