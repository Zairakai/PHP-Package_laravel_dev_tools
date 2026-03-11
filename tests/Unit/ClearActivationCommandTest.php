<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Tests\Unit;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Zairakai\LaravelDevTools\Console\Commands\Auth\ClearActivationCommand;
use Zairakai\LaravelDevTools\Tests\TestCase;

final class ClearActivationCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('activations', function ($table): void {
            $table->id();
            $table->string('email');
            $table->string('code');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('activations');
        Schema::dropIfExists('custom_activations');

        parent::tearDown();
    }

    #[Test]
    public function cancels_deletion_when_user_declines_confirmation(): void
    {
        DB::table('activations')->insert([
            'email'      => 'old@example.com',
            'code'       => 'OLD123',
            'created_at' => Date::now()->subDays(10),
            'updated_at' => Date::now()->subDays(10),
        ]);

        // Mock the confirm method to return false (user declines)
        $this->artisan('dev-tools:auth:clear-activations')
            ->expectsConfirmation('This will delete 1 activation codes older than 7 days. Continue?', 'no')
            ->assertExitCode(0)
            ->expectsOutput('Operation cancelled.');

        // Record should still exist since operation was cancelled
        $this->assertEquals(1, DB::table('activations')->count());
    }

    #[Test]
    public function cleans_up_old_soft_deleted_records(): void
    {
        // Expired activation (will be deleted by normal cleanup)
        DB::table('activations')->insert([
            'email'      => 'expired@example.com',
            'code'       => 'EXPIRED999',
            'created_at' => Date::now()->subDays(10),
            'updated_at' => Date::now()->subDays(10),
            'deleted_at' => null,
        ]);

        // Old soft-deleted (created recently, deleted 4 months ago) - will be force-deleted
        DB::table('activations')->insert([
            'email'      => 'soft@example.com',
            'code'       => 'SOFT123',
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
            'deleted_at' => Date::now()->subMonths(4),
        ]);

        // Recent soft-deleted (created recently, deleted 1 month ago) - should NOT be force-deleted
        DB::table('activations')->insert([
            'email'      => 'recent@example.com',
            'code'       => 'RECENT456',
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
            'deleted_at' => Date::now()->subMonth(),
        ]);

        // Fresh activation
        DB::table('activations')->insert([
            'email'      => 'fresh@example.com',
            'code'       => 'FRESH789',
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
            'deleted_at' => null,
        ]);

        $exitCode = Artisan::call('dev-tools:auth:clear-activations', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Also force-deleted 1 old soft-deleted activation codes', $output);

        // Both recent soft-deleted and fresh should remain (2 total)
        $this->assertEquals(2, DB::table('activations')->count());
    }

    #[Test]
    public function command_is_registered(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey(
            'dev-tools:auth:clear-activations',
            $commands,
            'dev-tools:auth:clear-activations command should be registered',
        );
    }

    #[Test]
    public function deletes_expired_activations(): void
    {
        DB::table('activations')->insert([
            'email'      => 'old@example.com',
            'code'       => 'OLD123',
            'created_at' => Date::now()->subDays(10),
            'updated_at' => Date::now()->subDays(10),
        ]);

        DB::table('activations')->insert([
            'email'      => 'new@example.com',
            'code'       => 'NEW456',
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);

        $exitCode = Artisan::call('dev-tools:auth:clear-activations', [
            '--days'           => 7,
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertEquals(1, DB::table('activations')->count());
    }

    #[Test]
    public function handles_custom_table_name(): void
    {
        Schema::create('custom_activations', function ($table): void {
            $table->id();
            $table->string('email');
            $table->string('code');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('custom_activations')->insert([
            'email'      => 'old@example.com',
            'code'       => 'OLD123',
            'created_at' => Date::now()->subDays(10),
            'updated_at' => Date::now()->subDays(10),
        ]);

        $exitCode = Artisan::call('dev-tools:auth:clear-activations', [
            'table'            => 'custom_activations',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertEquals(0, DB::table('custom_activations')->count());
    }

    #[Test]
    public function handles_no_expired_activations(): void
    {
        DB::table('activations')->insert([
            'email'      => 'test@example.com',
            'code'       => 'ABC123',
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
        ]);

        $exitCode = Artisan::call('dev-tools:auth:clear-activations', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('No activation codes to clear', $output);
        $this->assertEquals(1, DB::table('activations')->count());
    }

    #[Test]
    public function has_column_returns_false_for_nonexistent_column(): void
    {
        $clearActivationCommand = new ClearActivationCommand;

        $reflectionClass  = new ReflectionClass($clearActivationCommand);
        $reflectionMethod = $reflectionClass->getMethod('hasColumn');

        $result = $reflectionMethod->invoke($clearActivationCommand, 'activations', 'nonexistent_column');

        $this->assertFalse($result);
    }

    #[Test]
    public function has_column_returns_true_for_existing_column(): void
    {
        $clearActivationCommand = new ClearActivationCommand;

        $reflectionClass  = new ReflectionClass($clearActivationCommand);
        $reflectionMethod = $reflectionClass->getMethod('hasColumn');

        $result = $reflectionMethod->invoke($clearActivationCommand, 'activations', 'email');

        $this->assertTrue($result);
    }

    #[Test]
    public function isolation_lock_expires_at_returns_valid_interval(): void
    {
        $clearActivationCommand = new ClearActivationCommand;
        $result                 = $clearActivationCommand->isolationLockExpiresAt();

        $this->assertTrue(
            $result instanceof DateTimeInterface || $result instanceof DateInterval,
            'isolationLockExpiresAt should return DateTimeInterface or DateInterval',
        );
    }

    #[Test]
    public function proceeds_with_deletion_when_user_confirms(): void
    {
        DB::table('activations')->insert([
            'email'      => 'old@example.com',
            'code'       => 'OLD123',
            'created_at' => Date::now()->subDays(10),
            'updated_at' => Date::now()->subDays(10),
        ]);

        // Verify record exists before test
        $this->assertEquals(1, DB::table('activations')->count());

        // Mock the confirm method to return true (user accepts)
        $this->artisan('dev-tools:auth:clear-activations')
            ->expectsConfirmation('This will delete 1 activation codes older than 7 days. Continue?', 'yes')
            ->assertExitCode(0)
            ->run();

        // Record should be deleted after confirmation
        $this->assertEquals(0, DB::table('activations')->count());
    }

    #[Test]
    public function respects_custom_days_option(): void
    {
        DB::table('activations')->insert([
            'email'      => 'five@example.com',
            'code'       => 'FIVE',
            'created_at' => Date::now()->subDays(5),
            'updated_at' => Date::now()->subDays(5),
        ]);

        DB::table('activations')->insert([
            'email'      => 'fifteen@example.com',
            'code'       => 'FIFTEEN',
            'created_at' => Date::now()->subDays(15),
            'updated_at' => Date::now()->subDays(15),
        ]);

        $exitCode = Artisan::call('dev-tools:auth:clear-activations', [
            '--days'           => 10,
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertEquals(1, DB::table('activations')->count());
        $this->assertEquals('five@example.com', DB::table('activations')->first()->email);
    }

    #[Test]
    public function skips_soft_delete_cleanup_when_column_missing(): void
    {
        // Create table without deleted_at column
        Schema::create('simple_activations', function ($table): void {
            $table->id();
            $table->string('email');
            $table->string('code');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            // No deleted_at column
        });

        DB::table('simple_activations')->insert([
            'email'      => 'old@example.com',
            'code'       => 'OLD123',
            'created_at' => Date::now()->subDays(10),
            'updated_at' => Date::now()->subDays(10),
        ]);

        $exitCode = Artisan::call('dev-tools:auth:clear-activations', [
            'table'            => 'simple_activations',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        // Should not mention soft-deleted records
        $this->assertStringNotContainsString('force-deleted', $output);

        // Cleanup
        Schema::dropIfExists('simple_activations');
    }

    #[Test]
    public function soft_delete_cleanup_handles_no_old_soft_deleted_records(): void
    {
        // Add only fresh records and recent soft-deleted
        DB::table('activations')->insert([
            'email'      => 'fresh@example.com',
            'code'       => 'FRESH',
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
            'deleted_at' => null,
        ]);

        DB::table('activations')->insert([
            'email'      => 'recent@example.com',
            'code'       => 'RECENT',
            'created_at' => Date::now(),
            'updated_at' => Date::now(),
            'deleted_at' => Date::now()->subMonth(), // Only 1 month old, not 3+
        ]);

        $exitCode = Artisan::call('dev-tools:auth:clear-activations', [
            '--days'           => 30,
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        // Should not mention force-deleted since there were no old soft-deleted records
        $this->assertStringNotContainsString('force-deleted', $output);

        // Both records should still exist
        $this->assertEquals(2, DB::table('activations')->count());
    }

    #[Test]
    public function table_exists_returns_false_for_nonexistent_table(): void
    {
        $clearActivationCommand = new ClearActivationCommand;

        $reflectionClass  = new ReflectionClass($clearActivationCommand);
        $reflectionMethod = $reflectionClass->getMethod('tableExists');

        $result = $reflectionMethod->invoke($clearActivationCommand, 'nonexistent_table_xyz');

        $this->assertFalse($result);
    }

    #[Test]
    public function table_exists_returns_true_for_existing_table(): void
    {
        $clearActivationCommand = new ClearActivationCommand;

        $reflectionClass  = new ReflectionClass($clearActivationCommand);
        $reflectionMethod = $reflectionClass->getMethod('tableExists');

        $result = $reflectionMethod->invoke($clearActivationCommand, 'activations');

        $this->assertTrue($result);
    }

    #[Test]
    public function uses_default_table_name_when_not_specified(): void
    {
        DB::table('activations')->insert([
            'email'      => 'old@example.com',
            'code'       => 'OLD123',
            'created_at' => Date::now()->subDays(10),
            'updated_at' => Date::now()->subDays(10),
        ]);

        // Run without specifying table argument - should use 'activations' as default
        $exitCode = Artisan::call('dev-tools:auth:clear-activations', [
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        // Should have deleted the old activation from default table
        $this->assertEquals(0, DB::table('activations')->count());
    }

    #[Test]
    public function validates_table_exists(): void
    {
        Schema::dropIfExists('activations');

        $exitCode = Artisan::call('dev-tools:auth:clear-activations', [
            'table'            => 'activations',
            '--force'          => true,
            '--no-interaction' => true,
        ]);

        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString("Table 'activations' does not exist", $output);
    }
}
