<?php


// src/Http/Controllers/Api/RoleController.php
namespace LaravelAdRbac\Http\Controllers\Api;

use Illuminate\Http\Request;
use LaravelAdRbac\Models\Role;
use LaravelAdRbac\Models\Permission;
use LaravelAdRbac\Http\Resources\RoleResource;
use LaravelAdRbac\Http\Resources\PermissionResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleController extends BaseApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi('roles.read');

        $query = Role::query();

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by group
        if ($groupId = $request->get('group_id')) {
            $query->where('group_id', $groupId);
        }

        // Filter by system roles
        if ($request->has('is_system')) {
            $query->where('is_system', $request->boolean('is_system'));
        }

        // Eager load
        $query->with(['permissions', 'group', 'employees']);

        // Order by
        $orderBy = $request->get('order_by', 'name');
        $orderDir = $request->get('order_dir', 'asc');
        $query->orderBy($orderBy, $orderDir);

        $perPage = $request->get('per_page', config('ad-rbac.api.per_page', 20));
        $roles = $query->paginate($perPage);

        return $this->paginated($roles, 'Roles retrieved successfully');
    }

    public function store(Request $request)
    {
        $this->authorizeApi('roles.create');

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name',
            'slug' => 'nullable|string|max:100|unique:roles,slug',
            'description' => 'nullable|string|max:255',
            'group_id' => 'nullable|exists:groups,id',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'exists:permissions,id',
        ]);

        // Generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        DB::transaction(function () use ($validated, &$role) {
            $role = Role::create([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] ?? null,
                'group_id' => $validated['group_id'] ?? null,
            ]);

            // Assign permissions if provided
            if (!empty($validated['permission_ids'])) {
                $role->permissions()->sync($validated['permission_ids']);
            }
        });

        return $this->success(
            new RoleResource($role->load('permissions', 'group')),
            'Role created successfully',
            201
        );
    }

    public function show($id)
    {
        $this->authorizeApi('roles.read');

        $role = Role::with(['permissions', 'group', 'employees'])->findOrFail($id);

        return $this->success(
            new RoleResource($role),
            'Role retrieved successfully'
        );
    }

    public function update(Request $request, $id)
    {
        $this->authorizeApi('roles.update');

        $role = Role::findOrFail($id);

        // Prevent modification of system roles
        if ($role->is_system && !$request->user()->hasPermission('roles.manage-system')) {
            return $this->error('System roles cannot be modified', null, 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name,' . $id,
            'slug' => 'nullable|string|max:100|unique:roles,slug,' . $id,
            'description' => 'nullable|string|max:255',
            'group_id' => 'nullable|exists:groups,id',
        ]);

        // Generate slug if not provided
        if (empty($validated['slug']) && $validated['name'] !== $role->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $role->update($validated);

        return $this->success(
            new RoleResource($role->fresh()->load('permissions', 'group')),
            'Role updated successfully'
        );
    }

    public function destroy($id)
    {
        $this->authorizeApi('roles.delete');

        $role = Role::findOrFail($id);

        // Prevent deletion of system roles
        if ($role->is_system) {
            return $this->error('System roles cannot be deleted', null, 403);
        }

        // Check if role has users
        if ($role->employees()->count() > 0) {
            return $this->error('Cannot delete role that has users assigned', [
                'user_count' => $role->employees()->count(),
            ], 422);
        }

        DB::transaction(function () use ($role) {
            // Detach all permissions
            $role->permissions()->detach();
            // Delete the role
            $role->delete();
        });

        return $this->success(null, 'Role deleted successfully');
    }

    public function permissions($id)
    {
        $this->authorizeApi('roles.read');

        $role = Role::with('permissions')->findOrFail($id);

        return $this->success([
            'role' => new RoleResource($role),
            'permissions' => PermissionResource::collection($role->permissions),
            'available_permissions' => PermissionResource::collection(
                Permission::whereNotIn('id', $role->permissions->pluck('id'))->get()
            ),
        ], 'Role permissions retrieved successfully');
    }

    public function assignPermissions(Request $request, $id)
    {
        $this->authorizeApi('roles.manage-permissions');

        $role = Role::findOrFail($id);

        $validated = $request->validate([
            'permission_ids' => 'required|array',
            'permission_ids.*' => 'exists:permissions,id',
            'mode' => 'in:sync,attach,detach',
        ]);

        $mode = $validated['mode'] ?? 'sync';

        DB::transaction(function () use ($role, $validated, $mode) {
            switch ($mode) {
                case 'sync':
                    $role->permissions()->sync($validated['permission_ids']);
                    break;
                case 'attach':
                    $role->permissions()->attach($validated['permission_ids']);
                    break;
                case 'detach':
                    $role->permissions()->detach($validated['permission_ids']);
                    break;
            }

            // Clear permission cache for all users with this role
            $role->employees()->each(function ($employee) {
                $employee->clearPermissionCache();
            });
        });

        return $this->success(
            new RoleResource($role->fresh()->load('permissions')),
            'Permissions assigned successfully'
        );
    }

    public function employees($id)
    {
        $this->authorizeApi('roles.read');

        $role = Role::with('employees')->findOrFail($id);
        $employees = $role->employees()->paginate(20);

        return $this->paginated($employees, 'Role employees retrieved successfully');
    }
}