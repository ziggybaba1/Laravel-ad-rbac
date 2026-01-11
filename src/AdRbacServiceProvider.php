<?php

// src/AdRbacServiceProvider.php
namespace LaravelAdRbac;

use Illuminate\Support\ServiceProvider;
use LaravelAdRbac\Console\Kernel as AdRbacKernel;
use Illuminate\Console\Scheduling\Schedule;
use LaravelAdRbac\Services\AuditLogService;
use LaravelAdRbac\Services\RbacService;
use LaravelAdRbac\Services\EmployeeApiService; // Add this import
use LaravelAdRbac\Services\Implementations\RestEmployeeApiService; // Add this import
use LaravelAdRbac\Contracts\EmployeeApiInterface; // Add if you have an interface

class AdRbacServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/Config/ad-rbac.php', 'ad-rbac');

        // Register AD auth guard
        $this->app['auth']->extend('ad-session', function ($app, $name, array $config) {
            return new \LaravelAdRbac\Auth\AdSessionGuard(
                $name,
                $app['auth']->createUserProvider($config['provider']),
                $app['session.store'],
                $app['request']
            );
        });

        $this->app->singleton(RbacService::class, function ($app) {
            return new RbacService();
        });

        $this->app->singleton(AuditLogService::class, function ($app) {
            return new AuditLogService();
        });

        $this->app->register(\LaravelAdRbac\Providers\EmployeeApiServiceProvider::class);
    }

    public function boot()
    {
        // Publish configurations
        $this->publishes([
            __DIR__ . '/Config/ad-rbac.php' => config_path('ad-rbac.php'),
        ], 'config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/Database/Migrations/' => database_path('migrations'),
        ], 'migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Publish middleware stub
        // $this->publishes([
        //     __DIR__ . '/Stubs/middleware/' => app_path('Http/Middleware/'),
        // ], 'middleware');


        // Load commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallPackageCommand::class,
                Commands\ScanPermissionsCommand::class,
                Commands\SyncEmployeesCommand::class,
                Commands\CreateAdminCommand::class,
                // Commands\ManageRolesCommand::class,
                // Commands\CleanupOrphanedCommand::class,
                // Commands\ExportAssignmentsCommand::class,
            ]);
        }

        // Register middleware aliases
        $this->registerMiddlewareAliases();

        // Schedule tasks if running in console
        $this->app->booted(function () {
            $this->schedule(new Schedule());
        });

        // Load API routes
        // $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        // Publish API resources
        // $this->publishes([
        //     __DIR__ . '/../Http/Resources/' => app_path('Http/Resources/'),
        // ], 'api-resources');

        // Publish API controllers
        // $this->publishes([
        //     __DIR__ . '/../Http/Controllers/Api/' => app_path('Http/Controllers/Api/'),
        // ], 'api-controllers');
    }

    protected function registerMiddlewareAliases(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('ad-rbac', \LaravelAdRbac\Http\Middleware\AuthenticateWithAd::class);
        $router->aliasMiddleware('permission', \LaravelAdRbac\Http\Middleware\CheckPermission::class);
        $router->aliasMiddleware('role', \LaravelAdRbac\Http\Middleware\CheckRole::class);
        $router->aliasMiddleware('group', \LaravelAdRbac\Http\Middleware\CheckGroup::class);
        $router->aliasMiddleware('any-permission', \LaravelAdRbac\Http\Middleware\CheckAnyPermission::class);
    }

    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('ad-rbac:sync-employees --inactive-only')->dailyAt('02:00');
        $schedule->command('ad-rbac:scan-permissions')->dailyAt('03:00');
        $schedule->command('ad-rbac:cleanup-orphaned --permissions --roles')->weekly();
    }
}