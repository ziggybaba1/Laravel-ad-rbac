<?php

// src/Services/PermissionChecker.php
namespace LaravelAdRbac\Services;

use LaravelAdRbac\Models\Employee;
use LaravelAdRbac\Models\Assignment;
use Illuminate\Support\Facades\Cache;

class PermissionChecker
{
    /**
     * Check if employee has permission
     */
    public function hasPermission(Employee $employee, string $permissionSlug): bool
    {
        $cacheKey = "employee_has_permission_{$employee->id}_{$permissionSlug}";

        return Cache::remember($cacheKey, 300, function () use ($employee, $permissionSlug) {
            // Check direct permission assignment
            $directAssignment = $employee->permissions()
                ->where('slug', $permissionSlug)
                ->exists();

            if ($directAssignment) {
                return true;
            }

            // Check permission through roles
            $roleAssignment = $employee->roles()
                ->whereHas('permissions', function ($query) use ($permissionSlug) {
                    $query->where('slug', $permissionSlug);
                })
                ->exists();

            if ($roleAssignment) {
                return true;
            }

            // Check permission through groups -> roles
            $groupAssignment = $employee->groups()
                ->whereHas('roles', function ($query) use ($permissionSlug) {
                    $query->whereHas('permissions', function ($q) use ($permissionSlug) {
                        $q->where('slug', $permissionSlug);
                    });
                })
                ->exists();

            return $groupAssignment;
        });
    }

    /**
     * Get all effective permissions for employee
     */
    public function getEffectivePermissions(Employee $employee): array
    {
        $cacheKey = "employee_effective_permissions_{$employee->id}";

        return Cache::remember($cacheKey, 3600, function () use ($employee) {
            $permissions = collect();

            // Direct permissions
            $permissions = $permissions->merge($employee->permissions->pluck('slug'));

            // Permissions through roles
            foreach ($employee->roles as $role) {
                $permissions = $permissions->merge($role->permissions->pluck('slug'));
            }

            // Permissions through groups -> roles
            foreach ($employee->groups as $group) {
                foreach ($group->roles as $role) {
                    $permissions = $permissions->merge($role->permissions->pluck('slug'));
                }
            }

            return $permissions->unique()->values()->toArray();
        });
    }

    /**
     * Check if employee has any of the given permissions
     */
    public function hasAnyPermission(Employee $employee, array $permissionSlugs): bool
    {
        foreach ($permissionSlugs as $permissionSlug) {
            if ($this->hasPermission($employee, $permissionSlug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if employee has all of the given permissions
     */
    public function hasAllPermissions(Employee $employee, array $permissionSlugs): bool
    {
        foreach ($permissionSlugs as $permissionSlug) {
            if (!$this->hasPermission($employee, $permissionSlug)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get permission assignments for employee
     */
    public function getPermissionAssignments(Employee $employee): array
    {
        return [
            'direct' => $employee->permissions->pluck('slug')->toArray(),
            'through_roles' => $employee->roles->map(function ($role) {
                return [
                    'role' => $role->slug,
                    'permissions' => $role->permissions->pluck('slug')->toArray(),
                ];
            })->toArray(),
            'through_groups' => $employee->groups->map(function ($group) {
                return [
                    'group' => $group->slug,
                    'roles' => $group->roles->map(function ($role) {
                        return [
                            'role' => $role->slug,
                            'permissions' => $role->permissions->pluck('slug')->toArray(),
                        ];
                    })->toArray(),
                ];
            })->toArray(),
        ];
    }
}