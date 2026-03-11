<?php

declare(strict_types=1);

namespace Zairakai\LaravelDevTools\Console\Commands\Auth;

use DateInterval;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Command to clear expired activation codes from the database.
 *
 * Features:
 * - Deletes activations older than X days
 * - Optionally forces deletion without confirmation
 * - Cleans up old soft-deleted records (deleted_at)
 */
final class ClearActivationCommand extends Command
{
    protected $description = 'Clear outdated activation codes from database';

    protected $signature = 'dev-tools:auth:clear-activations
                            {table=activations : Activation table name}
                            {--days=7 : Delete activations older than X days}
                            {--force : Force delete without confirmation}';

    private readonly Carbon $now;

    /**
     * Capture the current timestamp at instantiation for consistent expiry calculations.
     */
    public function __construct()
    {
        parent::__construct();

        $this->now = Date::now();
    }

    /**
     * @codeCoverageIgnore Simple proxy — tested via handle()
     */
    public function __invoke(): void
    {
        $this->handle();
    }

    /**
     * Execute the command.
     */
    public function handle(): void
    {
        $table = $this->getTableName();
        $days  = max(1, (int) $this->option('days'));
        $force = (bool) $this->option('force');

        if (! $this->tableExists($table)) {
            $this->error(sprintf("Table '%s' does not exist.", $table));

            return;
        }

        $builder = $this->expiredActivationsQuery($table, $days);
        $count   = $builder->count();

        if (! $this->confirmDeletion($count, $days, $force)) {
            return;
        }

        $this->deleteActivations($builder, $table);
        $this->deleteOldSoftDeleted($table);
    }

    /**
     * Set isolation lock expiry (Laravel 10+).
     */
    public function isolationLockExpiresAt(): DateTimeInterface|DateInterval
    {
        return $this->now
            ->copy()
            ->addMinutes(30);
    }

    /**
     * Confirm deletion with user or force.
     */
    private function confirmDeletion(int $count, int $days, bool $force): bool
    {
        if (0 === $count) {
            $this->info('No activation codes to clear.');

            return false;
        }

        if ($force) {
            return true;
        }

        $message = sprintf(
            'This will delete %d activation codes older than %d days. Continue?',
            $count,
            $days,
        );

        if (! $this->confirm($message)) {
            $this->info('Operation cancelled.');

            return false;
        }

        return true;
    }

    /**
     * Delete expired activation codes.
     */
    private function deleteActivations(Builder $builder, string $table): void
    {
        $deleted = $builder->delete();

        $this->info(sprintf('Deleted %d activation codes from table %s.', $deleted, $table));
    }

    /**
     * Cleanup old soft-deleted activations if table has deleted_at.
     */
    private function deleteOldSoftDeleted(string $table): void
    {
        if (! $this->hasColumn($table, 'deleted_at')) {
            return;
        }

        $cutoff  = $this->now->copy()->subMonths(3);
        $deleted = DB::table($table)
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $cutoff)
            ->delete();

        if (0 < $deleted) {
            $this->info(sprintf('Also force-deleted %d old soft-deleted activation codes.', $deleted));
        }
    }

    /**
     * Create query for expired activations.
     */
    private function expiredActivationsQuery(string $table, int $days): Builder
    {
        $cutoff = $this->now
            ->copy()
            ->subDays($days);

        return DB::table($table)
            ->whereDate('created_at', '<', $cutoff);
    }

    private function getTableName(): string
    {
        $table = $this->argument('table');

        return is_string($table)
            ? $table
            : 'activations';
    }

    /**
     * Return true when the given table exists and contains the specified column.
     */
    private function hasColumn(string $table, string $column): bool
    {
        return $this->tableExists($table)
            && DB::getSchemaBuilder()->hasColumn($table, $column);
    }

    /**
     * Return true when the given table exists in the current database connection.
     */
    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }
}
