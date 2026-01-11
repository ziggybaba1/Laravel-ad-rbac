<?php

namespace LaravelAdRbac\Providers;

use Illuminate\Support\ServiceProvider;
use LaravelAdRbac\Services\EmployeeApiService;
use LaravelAdRbac\Services\Implementations\RestEmployeeApiService;
use LaravelAdRbac\Contracts\EmployeeApiInterface;

class EmployeeApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // $this->mergeConfigFrom(__DIR__ . '/../Config/employee_api.php', 'ad-rbac.employee_api');

        // Bind the abstract class to concrete implementation
        $this->app->singleton(EmployeeApiService::class, function ($app) {
            return new RestEmployeeApiService();
        });

        // Bind interface if it exists
        if (interface_exists(EmployeeApiInterface::class)) {
            $this->app->singleton(EmployeeApiInterface::class, function ($app) {
                return $app->make(EmployeeApiService::class);
            });
        }

        // Register alias for easier access
        $this->app->bind('employee.api', function ($app) {
            return $app->make(EmployeeApiService::class);
        });
    }

    public function boot(): void
    {
        // Publish configuration
        // $this->publishes([
        //     __DIR__ . '/../Config/employee_api.php' => config_path('ad-rbac/employee_api.php'),
        // ], 'employee-api-config');
    }
}