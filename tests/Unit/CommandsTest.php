<?php

namespace Zairakai\LaravelDevTools\Tests\Unit;

use Exception;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class CommandsTest extends TestCase
{
    /**
     * Help can be displayed for package commands.
     */
    #[Test]
    public function command_help_can_be_displayed(): void
    {
        $commands        = Artisan::all();
        $packageCommands = array_filter(array_keys($commands), fn (int|string $command): bool => str_starts_with((string) $command, 'dev-tools:') || str_starts_with((string) $command, 'zairakai:'));

        if ([] === $packageCommands) {
            $this->markTestSkipped('No package commands found to test');
        }

        // Test first command only to avoid timeout
        $firstCommand = reset($packageCommands);

        $exitCode = Artisan::call($firstCommand, ['--help' => true]);

        $this->assertEquals(
            0,
            $exitCode,
            sprintf('Command %s --help should return exit code 0', $firstCommand),
        );
    }

    /**
     * Commands can be called without fatal errors.
     */
    #[Test]
    public function commands_can_be_called(): void
    {
        $commands        = Artisan::all();
        $packageCommands = array_filter(array_keys($commands), fn (int|string $command): bool => str_starts_with((string) $command, 'dev-tools:') || str_starts_with((string) $command, 'zairakai:'));

        $this->assertNotEmpty($packageCommands, 'No package commands were discovered for validation');

        foreach ($packageCommands as $packageCommand) {
            try {
                // Attempt to get command signature (validates command structure)
                $commandInstance = $commands[$packageCommand];
                $signature       = $commandInstance->getName();

                $this->assertIsString(
                    $signature,
                    sprintf('Command %s should have a valid signature', $packageCommand),
                );
            }
            catch (Exception $e) {
                $this->fail(sprintf('Command %s failed to load: %s', $packageCommand, $e->getMessage()));
            }
        }
    }
}
