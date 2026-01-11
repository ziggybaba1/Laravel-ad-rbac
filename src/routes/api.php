<?php

// routes/api.php (in package)

use Illuminate\Support\Facades\Route;
use LaravelAdRbac\Http\Controllers\Api;

Route::prefix('api/ad-rbac')->name('api.ad-rbac.')->middleware(['api'])->group(function () {

    // Authentication
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('login', [Api\AuthController::class, 'login'])->name('login');
        Route::post('logout', [Api\AuthController::class, 'logout'])->name('logout')->middleware('auth:api');
        Route::get('user', [Api\AuthController::class, 'user'])->name('user')->middleware('auth:api');
        Route::get('permissions', [Api\AuthController::class, 'permissions'])->name('permissions')->middleware('auth:api');
    });

    // Employees
    Route::apiResource('employees', Api\EmployeeController::class)
        ->middleware(['auth:api', 'permission:employees.manage']);

    Route::prefix('employees/{employee}')->name('employees.')->group(function () {
        Route::get('roles', [Api\EmployeeController::class, 'roles'])->name('roles');
        Route::post('roles', [Api\EmployeeController::class, 'assignRoles'])->name('assign-roles');
        Route::get('permissions', [Api\EmployeeController::class, 'permissions'])->name('permissions');
    });

    // Roles
    Route::apiResource('roles', Api\RoleController::class)
        ->middleware(['auth:api', 'permission:roles.manage']);

    Route::prefix('roles/{role}')->name('roles.')->group(function () {
        Route::get('permissions', [Api\RoleController::class, 'permissions'])->name('permissions');
        Route::post('permissions', [Api\RoleController::class, 'assignPermissions'])->name('assign-permissions');
        Route::get('employees', [Api\RoleController::class, 'employees'])->name('employees');
    });

    // Permissions
    Route::prefix('permissions')->name('permissions.')->group(function () {
        Route::get('/', [Api\PermissionController::class, 'index'])->name('index');
        Route::get('modules', [Api\PermissionController::class, 'modules'])->name('modules');
        Route::get('actions', [Api\PermissionController::class, 'actions'])->name('actions');
        Route::get('grouped', [Api\PermissionController::class, 'grouped'])->name('grouped');
        Route::get('{permission}', [Api\PermissionController::class, 'show'])->name('show');
    })->middleware(['auth:api', 'permission:permissions.read']);

    // Groups
    Route::apiResource('groups', Api\GroupController::class)
        ->middleware(['auth:api', 'permission:groups.manage']);

    Route::prefix('groups/{group}')->name('groups.')->group(function () {
        Route::get('roles', [Api\GroupController::class, 'roles'])->name('roles');
        Route::get('employees', [Api\GroupController::class, 'employees'])->name('employees');
    });

    // Assignments
    Route::prefix('assignments')->name('assignments.')->middleware(['auth:api', 'permission:assignments.manage'])->group(function () {
        Route::post('assign-role', [Api\AssignmentController::class, 'assignRole'])->name('assign-role');
        Route::post('assign-permission', [Api\AssignmentController::class, 'assignPermission'])->name('assign-permission');
        Route::get('user/{user}/effective-permissions', [Api\AssignmentController::class, 'effectivePermissions'])->name('effective-permissions');
    });

    // System
    Route::prefix('system')->name('system.')->middleware(['auth:api', 'permission:system.manage'])->group(function () {
        Route::post('scan-permissions', [Api\SystemController::class, 'scanPermissions'])->name('scan-permissions');
        Route::post('sync-employees', [Api\SystemController::class, 'syncEmployees'])->name('sync-employees');
        Route::get('status', [Api\SystemController::class, 'status'])->name('status');
    });
});