<?php

// src/Commands/SyncEmployeesCommand.php
namespace LaravelAdRbac\Commands;

use Illuminate\Console\Command;
use LaravelAdRbac\Services\EmployeeSyncService;
use Illuminate\Support\Facades\Cache;

class SyncEmployeesCommand extends Command
{
    protected $signature = 'ad-rbac:sync-employees
                            {username? : Sync specific employee by username}
                            {--all : Sync all employees from API}
                            {--inactive-only : Sync only inactive employees}
                            {--force : Clear cache and force resync}
                            {--dry-run : Show what would be synced without making changes}';

    protected $description = 'Sync employee data from external API';

    public function handle(EmployeeSyncService $syncService)
    {
        $this->info('🔄 Syncing employee data...');

        if ($this->option('force')) {
            Cache::clear();
            $this->info('Cache cleared');
        }

        if ($username = $this->argument('username')) {
            $this->syncSingleEmployee($syncService, $username);
        } elseif ($this->option('all')) {
            $this->syncAllEmployees($syncService);
        } elseif ($this->option('inactive-only')) {
            $this->syncInactiveEmployees($syncService);
        } else {
            $this->syncRecentEmployees($syncService);
        }

        $this->info('✅ Employee sync completed');
    }

    // ... (sync methods implementation)
}