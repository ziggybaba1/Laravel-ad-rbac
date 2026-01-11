<?php

namespace LaravelAdRbac\Commands;

use Illuminate\Console\Command;
use LaravelAdRbac\Services\PermissionScanner;

class ScanPermissionsCommand extends Command
{
    protected $signature = 'ad-rbac:scan-permissions 
                            {--model= : Scan specific model only}
                            {--force : Force rescan of all models}';
    // {--d|verbose : Show detailed output}';  // Fixed: Remove duplicate 'verbose'

    protected $description = 'Scan and sync permissions from models';

    public function handle(PermissionScanner $scanner)
    {
        $this->info('🔍 Scanning for permissions...');

        if ($model = $this->option('model')) {
            $this->scanSingleModel($scanner, $model);
        } else {
            $this->scanAllModels($scanner);
        }

        $this->info('✅ Permission scan completed');
    }

    protected function scanSingleModel(PermissionScanner $scanner, string $model): void
    {
        try {
            $modelClass = 'App\\Models\\' . $model;

            if (!class_exists($modelClass)) {
                $this->error("Model {$modelClass} not found");
                return;
            }

            $this->info("Scanning model: {$model}");
            $scanner->syncModelPermissions($modelClass);

            // if ($this->option('verbose')) {
            $permissions = \LaravelAdRbac\Models\Permission::where('module', $modelClass)->get();
            $this->table(
                ['ID', 'Name', 'Slug', 'Action'],
                $permissions->map(fn($p) => [$p->id, $p->name, $p->slug, $p->action])
            );
            // }
        } catch (\Exception $e) {
            $this->error("Failed to scan {$model}: " . $e->getMessage());
        }
    }

    protected function scanAllModels(PermissionScanner $scanner): void
    {
        $models = $scanner->discoverPermissionableModels();

        $this->info("Found " . count($models) . " permissionable models");

        $progressBar = $this->output->createProgressBar(count($models));
        $progressBar->start();

        foreach ($models as $model) {
            try {
                $scanner->syncModelPermissions($model);
                $progressBar->advance();
            } catch (\Exception $e) {
                $this->warn("\nError scanning " . class_basename($model) . ": " . $e->getMessage());
            }
        }

        $progressBar->finish();
        $this->newLine();

        $totalPermissions = \LaravelAdRbac\Models\Permission::count();
        $this->info("Total permissions in system: {$totalPermissions}");
    }
}