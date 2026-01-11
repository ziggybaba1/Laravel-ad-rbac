<?php

// src/Console/Kernel.php
namespace LaravelAdRbac\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // Auto-sync employees daily at 2 AM
        $schedule->command('ad-rbac:sync-employees --inactive-only')
            ->dailyAt('02:00')
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/ad-rbac-sync.log'));

        // Auto-scan permissions daily at 3 AM
        $schedule->command('ad-rbac:scan-permissions')
            ->dailyAt('03:00')
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/ad-rbac-permissions.log'));

        // Cleanup orphaned data weekly on Sunday at 4 AM
        $schedule->command('ad-rbac:cleanup-orphaned --permissions --roles')
            ->weeklyOn(0, '04:00')
            ->appendOutputTo(storage_path('logs/ad-rbac-cleanup.log'));

        // Backup role assignments monthly
        $schedule->command('ad-rbac:export-assignments')
            ->monthlyOn(1, '05:00')
            ->appendOutputTo(storage_path('logs/ad-rbac-backup.log'));
    }
}