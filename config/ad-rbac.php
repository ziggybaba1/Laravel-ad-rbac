<?php

use LaravelAdRbac\Models\Employee;
use LaravelAdRbac\Models\Permission;
use LaravelAdRbac\Models\Role;
use LaravelAdRbac\Models\Group;

return [
    'ad' => [
        'enabled' => env('AD_AUTH_ENABLED', true),
        'server' => env('AD_SERVER', 'localhost'),
        'domain' => env('AD_DOMAIN', ''),
        'base_dn' => env('AD_BASE_DN', ''),
        'port' => env('AD_PORT', 389),
        'timeout' => env('AD_TIMEOUT', 5),
        'use_ssl' => env('AD_USE_SSL', false),
        'use_tls' => env('AD_USE_TLS', true),
    ],

    'employee_api' => [
        'base_url' => env('EMPLOYEE_API_URL', ''),
        'secret_key' => env('EMPLOYEE_API_SECRET', ''),
        'timeout' => env('EMPLOYEE_API_TIMEOUT', 30),
        'cache_ttl' => env('EMPLOYEE_CACHE_TTL', 3600), // 1 hour
    ],

    'permissions' => [
        'auto_scan' => env('PERMISSION_AUTO_SCAN', true),
        'scan_interval' => env('PERMISSION_SCAN_INTERVAL', 'daily'), // hourly, daily, weekly
        'special_actions' => ['approve', 'assign', 'review', 'audit', 'process', 'verify'],
        'excluded_models' => [
            \Illuminate\Database\Eloquent\Model::class,
            Employee::class,
            Permission::class,
            Role::class,
            Group::class,
        ],
        'excluded_tables' => ['migrations', 'failed_jobs', 'password_resets'],
    ],

    'models' => [
        'employee' => Employee::class,
        'permission' => Permission::class,
        'role' => Role::class,
        'group' => Group::class,
    ],

    'routes' => [
        'prefix' => 'admin',
        'middleware' => ['web', 'ad-rbac'],
        'login_path' => '/login',
        'dashboard_path' => '/dashboard',
    ],
    'api' => [
        'enabled' => env('AD_RBAC_API_ENABLED', true),
        'prefix' => 'api/ad-rbac',
        'version' => '1.0.0',
        'per_page' => 20,
        'max_per_page' => 100,
        'rate_limit' => [
            'enabled' => true,
            'requests' => 60,
            'period' => 1, // minutes
        ],
        'middleware' => ['api'],
        'auth_middleware' => ['auth:api'],
        'throttle' => '60,1',

        // OpenAPI/Swagger
        'swagger' => [
            'enabled' => env('AD_RBAC_SWAGGER_ENABLED', true),
            'path' => 'api/docs/ad-rbac',
            'title' => 'AD RBAC API Documentation',
            'version' => '1.0.0',
            'description' => 'API for managing AD authentication and RBAC permissions',
        ],
    ],
];