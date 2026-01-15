<?php

namespace LaravelAdRbac\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class InstallPackageCommand extends Command
{
    protected $signature = 'ad-rbac:install 
                            {--force : Force overwrite existing files}
                            {--no-migrate : Skip running migrations}
                            {--no-scan : Skip initial permission scan}
                            {--test-ad : Test AD connection after installation}';

    protected $description = 'Install the AD RBAC package';

    public function handle()
    {
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║      Laravel AD RBAC Package Installation       ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();

        // 1. Publish configuration
        $this->publishConfiguration();

        // 2. Update auth configuration
        $this->updateAuthConfig();

        // 3. Register middleware
        $this->registerMiddleware();

        // 4. Run migrations (unless skipped)
        if (!$this->option('no-migrate')) {
            $this->runMigrations();
        }

        // 5. Scan for initial permissions (unless skipped)
        if (!$this->option('no-scan')) {
            $this->scanPermissions();
        }

        // 6. Test AD connection if requested
        if ($this->option('test-ad')) {
            $this->testAdConnection();
        }

        $this->newLine();
        $this->info('Package installed successfully!');
        $this->newLine();

        $this->displayNextSteps();

        return 0;
    }

    protected function publishConfiguration(): void
    {
        $this->info('📁 Publishing configuration...');

        $configPath = config_path('ad-rbac.php');

        if (File::exists($configPath) && !$this->option('force')) {
            if (!$this->confirm('Configuration file already exists. Overwrite?', false)) {
                $this->info('   Skipping configuration publish');
                return;
            }
        }

        // Copy config directly instead of vendor:publish to avoid route issues
        $sourceConfig = __DIR__ . '/../../config/ad-rbac.php';
        if (File::exists($sourceConfig)) {
            File::copy($sourceConfig, $configPath);
            $this->info('   Configuration published: config/ad-rbac.php');
        } else {
            $this->warn('   Configuration source file not found');
        }
    }

    protected function updateAuthConfig(): void
    {
        $this->info(' Updating authentication configuration...');

        $configPath = config_path('auth.php');

        if (!File::exists($configPath)) {
            $this->warn('   Auth config file not found');
            return;
        }

        $contents = File::get($configPath);
        $updated = false;

        // Check if providers already contain employees
        if (!Str::contains($contents, "'employees' => [")) {
            // Update providers - add employees provider
            $providerPattern = "/'providers' => \[(.*?)\],/s";
            $newProvider = "'providers' => [
        'employees' => [
            'driver' => 'eloquent',
            'model' => LaravelAdRbac\\Models\\Employee::class,
        ],";

            $contents = preg_replace($providerPattern, $newProvider, $contents, 1);
            $updated = true;
        }

        // Check if ad guard exists
        if (!Str::contains($contents, "'ad' => [")) {
            // Update guards - add ad guard
            $guardPattern = "/'guards' => \[(.*?)\],/s";
            $newGuard = "'guards' => [
        'web' => [
            'driver' => 'ad-session',
            'provider' => 'employees',
        ],
        
        'api' => [
            'driver' => 'token',
            'provider' => 'employees',
            'hash' => false,
        ],
        
        'ad' => [
            'driver' => 'ad-session',
            'provider' => 'employees',
        ],";

            $contents = preg_replace($guardPattern, $newGuard, $contents, 1);
            $updated = true;
        }

        if ($updated) {
            File::put($configPath, $contents);
            $this->info('   Auth configuration updated');
        } else {
            $this->info('   Auth configuration already up to date');
        }
    }

    protected function registerMiddleware(): void
    {
        $this->info(' Registering middleware...');

        $bootstrapPath = base_path('bootstrap/app.php');

        if (!File::exists($bootstrapPath)) {
            $this->warn('   Bootstrap file not found (Laravel 11 structure?)');
            return;
        }

        $contents = File::get($bootstrapPath);

        // Check if middleware already registered
        if (Str::contains($contents, "'permission' =>")) {
            $this->info('   Middleware already registered');
            return;
        }

        // For Laravel 11+ with bootstrap/app.php
        if (Str::contains($contents, '->withMiddleware(')) {
            // Insert into existing withMiddleware call
            $pattern = "/->withMiddleware\(function.*?\\\$middleware.*?\{/s";
            $replacement = "$0\n        \\\$middleware->alias([\n            'ad-rbac' => \\LaravelAdRbac\\Http\\Middleware\\AuthenticateWithAd::class,\n            'permission' => \\LaravelAdRbac\\Http\\Middleware\\CheckPermission::class,\n        ]);";

            $contents = preg_replace($pattern, $replacement, $contents, 1);
        } else {
            // Add withMiddleware call
            $insertion = <<<'PHP'

    ->withMiddleware(function ($middleware) {
        $middleware->alias([
            'ad-rbac' => \LaravelAdRbac\Http\Middleware\AuthenticateWithAd::class,
            'permission' => \LaravelAdRbac\Http\Middleware\CheckPermission::class,
        ]);
    })
PHP;

            $contents = preg_replace(
                '/->create\(\);/',
                $insertion . "\n\n    ->create();",
                $contents,
                1
            );
        }

        File::put($bootstrapPath, $contents);
        $this->info('   Middleware registered in bootstrap/app.php');
    }

    protected function runMigrations(): void
    {
        $this->info('Running migrations...');

        try {
            // First, check if migrations table exists
            if (!\Schema::hasTable('migrations')) {
                Artisan::call('migrate:install', [], $this->getOutput());
            }

            // Run migrations using loadMigrationsFrom (no publishing needed)
            Artisan::call('migrate', [
                '--force' => true,
                '--path' => 'vendor/ziggybaba1/laravel-ad-rbac/database/Migrations'
            ]);

            $this->info('   Database migrated successfully');

        } catch (\Exception $e) {
            $this->error('   Migration failed: ' . $e->getMessage());

            // Provide helpful error message
            if (Str::contains($e->getMessage(), 'already in use')) {
                $this->error('   Migration class name conflict detected!');
                $this->line('   This usually happens when migration class names are duplicated.');
                $this->line('   Try: php artisan migrate:fresh --force');
            }

            if (!$this->confirm('Continue with installation?', true)) {
                exit(1);
            }
        }
    }

    protected function scanPermissions(): void
    {
        $this->info('🔍 Scanning for permissions...');

        try {
            Artisan::call('ad-rbac:scan-permissions');
            $this->info('   Permission scan completed');
        } catch (\Exception $e) {
            $this->warn('   Permission scan failed: ' . $e->getMessage());
            $this->line('   You can run it manually later: php artisan ad-rbac:scan-permissions');
        }
    }

    protected function testAdConnection(): void
    {
        $this->info('🔗 Testing AD connection...');

        try {
            Artisan::call('ad-rbac:test-ad', ['--validate' => true], $this->getOutput());
        } catch (\Exception $e) {
            $this->warn('   AD connection test failed: ' . $e->getMessage());
        }
    }

    protected function displayNextSteps(): void
    {
        $this->info(' NEXT STEPS:');
        $this->newLine();

        $this->line('1. Configure your AD settings in config/ad-rbac.php:');
        $this->line('   - Set AD_SERVER, AD_DOMAIN in .env');
        $this->line('   - Configure AD admin credentials if needed');
        $this->newLine();

        $this->line('2. Add the Permissionable trait to your models:');
        $this->line('   use LaravelAdRbac\Traits\Permissionable;');
        $this->newLine();

        $this->line('3. Use middleware in your routes:');
        $this->line('   Route::middleware([\'permission:model.action\'])->...');
        $this->line('   Route::middleware([\'ad-rbac\'])->...');
        $this->newLine();

        $this->line('4. Useful commands:');
        $this->line('   php artisan ad-rbac:scan-permissions');
        $this->line('   php artisan ad-rbac:sync-employees');
        $this->line('   php artisan ad-rbac:test-ad --validate');
        $this->newLine();

        $this->line(' Tip: Check the README.md for detailed usage instructions.');
    }
}