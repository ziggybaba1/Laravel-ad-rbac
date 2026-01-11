<?php

// src/Http/Controllers/Api/PermissionController.php
namespace LaravelAdRbac\Http\Controllers\Api;

use Illuminate\Http\Request;
use LaravelAdRbac\Models\Permission;
use LaravelAdRbac\Http\Resources\PermissionResource;
use Illuminate\Support\Collection;

class PermissionController extends BaseApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi('permissions.read');

        $query = Permission::query();

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('module', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        // Filter by module
        if ($module = $request->get('module')) {
            $query->where('module', $module);
        }

        // Filter by action
        if ($action = $request->get('action')) {
            $query->where('action', $action);
        }

        // Filter by category
        if ($category = $request->get('category')) {
            $query->where('category', $category);
        }

        // Eager load
        $query->with('roles');

        // Order by
        $orderBy = $request->get('order_by', 'module');
        $orderDir = $request->get('order_dir', 'asc');
        $query->orderBy($orderBy, $orderDir);

        $perPage = $request->get('per_page', config('ad-rbac.api.per_page', 50));
        $permissions = $query->paginate($perPage);

        return $this->paginated($permissions, 'Permissions retrieved successfully');
    }

    public function modules()
    {
        $this->authorizeApi('permissions.read');

        $modules = Permission::distinct('module')
            ->orderBy('module')
            ->pluck('module')
            ->map(function ($module) {
                return [
                    'module' => $module,
                    'module_name' => class_basename($module),
                    'permission_count' => Permission::where('module', $module)->count(),
                ];
            })
            ->values();

        return $this->success($modules, 'Modules retrieved successfully');
    }

    public function actions()
    {
        $this->authorizeApi('permissions.read');

        $actions = Permission::distinct('action')
            ->orderBy('action')
            ->pluck('action');

        return $this->success($actions, 'Actions retrieved successfully');
    }

    public function grouped()
    {
        $this->authorizeApi('permissions.read');

        $permissions = Permission::with('roles')
            ->orderBy('module')
            ->orderBy('action')
            ->get()
            ->groupBy('module');

        $grouped = $permissions->map(function ($perms, $module) {
            return [
                'module' => $module,
                'module_name' => class_basename($module),
                'permissions' => PermissionResource::collection($perms),
                'actions' => $perms->pluck('action')->unique()->values(),
            ];
        })->values();

        return $this->success($grouped, 'Grouped permissions retrieved successfully');
    }

    public function show($id)
    {
        $this->authorizeApi('permissions.read');

        $permission = Permission::with('roles')->findOrFail($id);

        return $this->success(
            new PermissionResource($permission),
            'Permission retrieved successfully'
        );
    }
}