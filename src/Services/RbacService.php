<?php

namespace LaravelAdRbac\Services;

use LaravelAdRbac\Repositories\PermissionRepository;
use LaravelAdRbac\Repositories\RoleRepository;
use LaravelAdRbac\Repositories\GroupRepository;
use LaravelAdRbac\Models\Permission;
use LaravelAdRbac\Models\Role;
use LaravelAdRbac\Models\Group;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RbacService
{
    protected $permissionRepository;
    protected $roleRepository;
    protected $groupRepository;

    protected $auditLogService;

    public function __construct(
        PermissionRepository $permissionRepository = null,
        RoleRepository $roleRepository = null,
        GroupRepository $groupRepository = null
    ) {
        $this->permissionRepository = $permissionRepository ?? new PermissionRepository();
        $this->roleRepository = $roleRepository ?? new RoleRepository();
        $this->groupRepository = $groupRepository ?? new GroupRepository();
        $this->auditLogService = new AuditLogService();
    }


    public function assignPermissionToRole(int $roleId, int $permissionId): bool
    {
        $role = $this->roleRepository->find($roleId);
        $permission = $this->permissionRepository->find($permissionId);

        if (!$role || !$permission) {
            return false;
        }

        if (!$role->permissions()->where('permission_id', $permissionId)->exists()) {
            $role->permissions()->attach($permissionId);
        }

        return true;
    }

    /**
     * Mass assign multiple permissions to a role
     */
    public function massAssignPermissionsToRole(int $roleId, array $permissionIds): bool
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return false;
        }

        // Filter out existing permissions
        $existingPermissionIds = $role->permissions()->pluck('permission_id')->toArray();
        $newPermissionIds = array_diff($permissionIds, $existingPermissionIds);

        if (empty($newPermissionIds)) {
            return true;
        }

        // Bulk insert for performance
        $records = array_map(function ($permissionId) use ($roleId) {
            return [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $newPermissionIds);

        DB::table('role_permission')->insert($records);

        return true;
    }

    /**
     * Assign permissions to multiple roles (mass assignment)
     */
    public function assignPermissionsToRoles(array $assignments): bool
    {
        $records = [];
        $now = now();

        foreach ($assignments as $roleId => $permissionIds) {
            if (!is_array($permissionIds)) {
                $permissionIds = [$permissionIds];
            }

            foreach ($permissionIds as $permissionId) {
                $records[] = [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (empty($records)) {
            return false;
        }

        // Use insertOrIgnore to avoid duplicates
        DB::table('role_permission')->insertOrIgnore($records);

        return true;
    }

    /**
     * Remove single permission from a role
     */
    public function removePermissionFromRole(int $roleId, int $permissionId): bool
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return false;
        }

        $role->permissions()->detach($permissionId);

        return true;
    }

    /**
     * Mass remove multiple permissions from a role
     */
    public function massRemovePermissionsFromRole(int $roleId, array $permissionIds): bool
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return false;
        }

        if (empty($permissionIds)) {
            return false;
        }

        $role->permissions()->detach($permissionIds);

        return true;
    }

    /**
     * Remove permissions from multiple roles (mass removal)
     */
    public function removePermissionsFromRoles(array $assignments): bool
    {
        foreach ($assignments as $roleId => $permissionIds) {
            if (!is_array($permissionIds)) {
                $permissionIds = [$permissionIds];
            }

            $role = $this->roleRepository->find($roleId);
            if ($role) {
                $role->permissions()->detach($permissionIds);
            }
        }

        return true;
    }

    /**
     * Sync all permissions for a role (replace existing)
     */
    public function syncPermissionsForRole(int $roleId, array $permissionIds): bool
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return false;
        }

        $role->permissions()->sync($permissionIds);

        return true;
    }

    /**
     * Check if role has specific permission
     */
    public function roleHasPermission(int $roleId, int $permissionId): bool
    {
        return DB::table('role_permission')
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->exists();
    }

    /**
     * Get all permissions for a role
     */
    public function getRolePermissions(int $roleId, array $excludeColumns = [], int $perPage = null): Collection|LengthAwarePaginator
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return new Collection();
        }

        $query = $role->permissions();

        if (!empty($excludeColumns)) {
            $allColumns = $this->permissionRepository->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        if ($perPage) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * Get roles that have a specific permission
     */
    public function getRolesWithPermission(int $permissionId, array $excludeColumns = [], int $perPage = null): Collection|LengthAwarePaginator
    {
        $permission = $this->permissionRepository->find($permissionId);

        if (!$permission) {
            return new Collection();
        }

        $query = $permission->roles();

        if (!empty($excludeColumns)) {
            $allColumns = $this->roleRepository->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        if ($perPage) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * =============== ROLE TO GROUP ASSIGNMENTS ===============
     */

    /**
     * Assign single role to a group
     */
    public function assignRoleToGroup(int $groupId, int $roleId): bool
    {
        $group = $this->groupRepository->find($groupId);
        $role = $this->roleRepository->find($roleId);

        if (!$group || !$role) {
            return false;
        }

        $role->group_id = $groupId;
        return $role->save();
    }

    /**
     * Mass assign multiple roles to a group
     */
    public function massAssignRolesToGroup(int $groupId, array $roleIds): bool
    {
        if (empty($roleIds)) {
            return false;
        }

        // Validate group exists
        if (!$this->groupRepository->find($groupId)) {
            return false;
        }

        // Update all roles in one query
        return Role::whereIn('id', $roleIds)->update(['group_id' => $groupId]) > 0;
    }

    /**
     * Assign roles to multiple groups (mass assignment)
     */
    public function assignRolesToGroups(array $assignments): bool
    {
        $updates = [];

        foreach ($assignments as $groupId => $roleIds) {
            if (!is_array($roleIds)) {
                $roleIds = [$roleIds];
            }

            foreach ($roleIds as $roleId) {
                $updates[] = [
                    'id' => $roleId,
                    'group_id' => $groupId,
                ];
            }
        }

        if (empty($updates)) {
            return false;
        }

        // Batch update using case statement
        $caseStatements = [];
        $ids = [];

        foreach ($updates as $update) {
            $ids[] = $update['id'];
            $caseStatements[] = "WHEN {$update['id']} THEN {$update['group_id']}";
        }

        $idsString = implode(',', $ids);
        $caseString = implode(' ', $caseStatements);

        DB::update("
            UPDATE roles 
            SET group_id = CASE id {$caseString} END,
                updated_at = NOW()
            WHERE id IN ({$idsString})
        ");

        return true;
    }

    /**
     * Remove single role from its group (set group_id to null)
     */
    public function removeRoleFromGroup(int $roleId): bool
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return false;
        }

        $role->group_id = null;
        return $role->save();
    }

    /**
     * Mass remove multiple roles from their groups
     */
    public function massRemoveRolesFromGroups(array $roleIds): bool
    {
        if (empty($roleIds)) {
            return false;
        }

        return Role::whereIn('id', $roleIds)->update(['group_id' => null]) > 0;
    }

    /**
     * Sync roles for a group (replace all roles in group)
     */
    public function syncRolesForGroup(int $groupId, array $roleIds): bool
    {
        // First, remove all roles from this group
        Role::where('group_id', $groupId)->update(['group_id' => null]);

        // Then assign new roles if provided
        if (!empty($roleIds)) {
            return $this->massAssignRolesToGroup($groupId, $roleIds);
        }

        return true;
    }

    /**
     * Get all roles in a group
     */
    public function getGroupRoles(int $groupId, array $excludeColumns = [], int $perPage = null): Collection|LengthAwarePaginator
    {
        $group = $this->groupRepository->find($groupId);

        if (!$group) {
            return new Collection();
        }

        $query = $group->roles();

        if (!empty($excludeColumns)) {
            $allColumns = $this->roleRepository->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        // Load permissions for each role
        $query->with('permissions');

        if ($perPage) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * Get group for a specific role
     */
    public function getRoleGroup(int $roleId): ?Group
    {
        $role = $this->roleRepository->find($roleId);

        return $role ? $role->group : null;
    }

    /**
     * =============== BULK OPERATIONS ===============
     */

    /**
     * Bulk assign permissions to roles and roles to groups
     */
    public function bulkAssign(array $data): array
    {
        $results = [
            'permission_to_role' => false,
            'role_to_group' => false,
            'messages' => []
        ];

        // Assign permissions to roles
        if (!empty($data['permission_assignments'])) {
            try {
                $results['permission_to_role'] = $this->assignPermissionsToRoles($data['permission_assignments']);
                $results['messages'][] = 'Permission assignments completed.';
            } catch (\Exception $e) {
                $results['messages'][] = 'Permission assignment failed: ' . $e->getMessage();
            }
        }

        // Assign roles to groups
        if (!empty($data['role_assignments'])) {
            try {
                $results['role_to_group'] = $this->assignRolesToGroups($data['role_assignments']);
                $results['messages'][] = 'Role assignments completed.';
            } catch (\Exception $e) {
                $results['messages'][] = 'Role assignment failed: ' . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Bulk remove assignments
     */
    public function bulkRemove(array $data): array
    {
        $results = [
            'permission_from_role' => false,
            'role_from_group' => false,
            'messages' => []
        ];

        // Remove permissions from roles
        if (!empty($data['permission_removals'])) {
            try {
                $results['permission_from_role'] = $this->removePermissionsFromRoles($data['permission_removals']);
                $results['messages'][] = 'Permission removals completed.';
            } catch (\Exception $e) {
                $results['messages'][] = 'Permission removal failed: ' . $e->getMessage();
            }
        }

        // Remove roles from groups
        if (!empty($data['role_removals'])) {
            try {
                $results['role_from_group'] = $this->massRemoveRolesFromGroups($data['role_removals']);
                $results['messages'][] = 'Role removals completed.';
            } catch (\Exception $e) {
                $results['messages'][] = 'Role removal failed: ' . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Get assignment statistics
     */
    public function getAssignmentStats(): array
    {
        $stats = [];

        // Permission to role stats
        $stats['permission_assignments'] = DB::table('role_permission')
            ->select(
                DB::raw('COUNT(*) as total_assignments'),
                DB::raw('COUNT(DISTINCT role_id) as roles_with_permissions'),
                DB::raw('COUNT(DISTINCT permission_id) as assigned_permissions')
            )
            ->first();

        // Role to group stats
        $stats['role_assignments'] = DB::table('roles')
            ->select(
                DB::raw('COUNT(*) as total_roles'),
                DB::raw('COUNT(CASE WHEN group_id IS NOT NULL THEN 1 END) as assigned_roles'),
                DB::raw('COUNT(DISTINCT group_id) as groups_with_roles')
            )
            ->first();

        // Top permissions by assignment count
        $stats['top_permissions'] = DB::table('role_permission')
            ->select('permission_id', DB::raw('COUNT(*) as role_count'))
            ->groupBy('permission_id')
            ->orderByDesc('role_count')
            ->limit(10)
            ->get();

        // Top roles by permission count
        $stats['top_roles'] = DB::table('role_permission')
            ->select('role_id', DB::raw('COUNT(*) as permission_count'))
            ->groupBy('role_id')
            ->orderByDesc('permission_count')
            ->limit(10)
            ->get();

        return $stats;
    }

    /**
     * =============== VALIDATION METHODS ===============
     */

    /**
     * Validate permission exists and is not system
     */
    public function isValidPermission(int $permissionId, bool $allowSystem = false): bool
    {
        $permission = $this->permissionRepository->find($permissionId);

        if (!$permission) {
            return false;
        }

        if ($permission->is_system && !$allowSystem) {
            return false;
        }

        return true;
    }

    /**
     * Validate role exists and is not system
     */
    public function isValidRole(int $roleId, bool $allowSystem = false): bool
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return false;
        }

        if ($role->is_system && !$allowSystem) {
            return false;
        }

        return true;
    }

    /**
     * Validate group exists and is not system
     */
    public function isValidGroup(int $groupId, bool $allowSystem = false): bool
    {
        $group = $this->groupRepository->find($groupId);

        if (!$group) {
            return false;
        }

        if ($group->is_system && !$allowSystem) {
            return false;
        }

        return true;
    }

    /**
     * Validate bulk assignment data
     */
    public function validateBulkAssignment(array $data): array
    {
        $errors = [];

        // Validate permission assignments
        if (!empty($data['permission_assignments'])) {
            foreach ($data['permission_assignments'] as $roleId => $permissionIds) {
                if (!$this->isValidRole($roleId)) {
                    $errors[] = "Invalid role ID: {$roleId}";
                }

                if (!is_array($permissionIds)) {
                    $permissionIds = [$permissionIds];
                }

                foreach ($permissionIds as $permissionId) {
                    if (!$this->isValidPermission($permissionId)) {
                        $errors[] = "Invalid permission ID: {$permissionId} for role: {$roleId}";
                    }
                }
            }
        }

        // Validate role assignments
        if (!empty($data['role_assignments'])) {
            foreach ($data['role_assignments'] as $groupId => $roleIds) {
                if (!$this->isValidGroup($groupId)) {
                    $errors[] = "Invalid group ID: {$groupId}";
                }

                if (!is_array($roleIds)) {
                    $roleIds = [$roleIds];
                }

                foreach ($roleIds as $roleId) {
                    if (!$this->isValidRole($roleId)) {
                        $errors[] = "Invalid role ID: {$roleId} for group: {$groupId}";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * =============== PERMISSIONS ===============
     */

    /**
     * Get all permissions with optional column exclusion and pagination
     */
    public function getAllPermissions(
        array $excludeColumns = [],
        int $perPage = null,
        array $filters = []
    ): Collection|LengthAwarePaginator {
        return $this->permissionRepository->search($filters, $excludeColumns, $perPage);
    }

    /**
     * Get permissions by category
     */
    public function getPermissionsByCategory(
        string $category,
        array $excludeColumns = [],
        int $perPage = null
    ): Collection|LengthAwarePaginator {
        return $this->getAllPermissions($excludeColumns, $perPage, ['category' => $category]);
    }

    /**
     * Get permissions by module
     */
    public function getPermissionsByModule(
        string $module,
        array $excludeColumns = [],
        int $perPage = null
    ): Collection|LengthAwarePaginator {
        return $this->getAllPermissions($excludeColumns, $perPage, ['module' => $module]);
    }

    /**
     * Get permissions by action
     */
    public function getPermissionsByAction(
        string $action,
        array $excludeColumns = [],
        int $perPage = null
    ): Collection|LengthAwarePaginator {
        return $this->getAllPermissions($excludeColumns, $perPage, ['action' => $action]);
    }

    /**
     * Create a new permission
     */
    public function createPermission(array $data): Permission
    {
        // Ensure slug is generated if not provided
        if (!isset($data['slug']) && isset($data['name'])) {
            $data['slug'] = \Illuminate\Support\Str::slug($data['name']);
        }

        return $this->permissionRepository->create($data);
    }

    /**
     * Update a permission
     */
    public function updatePermission(int $id, array $data): bool
    {
        return $this->permissionRepository->update($id, $data);
    }

    /**
     * Delete a permission
     */
    public function deletePermission(int $id): bool
    {
        return $this->permissionRepository->delete($id);
    }

    /**
     * =============== ROLES ===============
     */

    /**
     * Get all roles with optional column exclusion and pagination
     */
    public function getAllRoles(
        array $excludeColumns = [],
        int $perPage = null,
        array $filters = []
    ): Collection|LengthAwarePaginator {
        $query = Role::query();

        // Apply filters
        foreach ($filters as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        // Load relationships
        $query->with(['group', 'permissions']);

        // Exclude columns
        if (!empty($excludeColumns)) {
            $allColumns = $this->roleRepository->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        // Paginate or get all
        if ($perPage) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * Get roles by group
     */
    public function getRolesByGroup(
        int $groupId,
        array $excludeColumns = [],
        int $perPage = null
    ): Collection|LengthAwarePaginator {
        return $this->getAllRoles($excludeColumns, $perPage, ['group_id' => $groupId]);
    }

    /**
     * Get system roles
     */
    public function getSystemRoles(
        array $excludeColumns = [],
        int $perPage = null
    ): Collection|LengthAwarePaginator {
        return $this->getAllRoles($excludeColumns, $perPage, ['is_system' => true]);
    }

    /**
     * Create a new role with optional permissions
     */
    public function createRole(array $data, array $permissionIds = []): Role
    {
        // Ensure slug is generated if not provided
        if (!isset($data['slug']) && isset($data['name'])) {
            $data['slug'] = \Illuminate\Support\Str::slug($data['name']);
        }

        $role = $this->roleRepository->create($data);

        // Attach permissions if provided
        if (!empty($permissionIds)) {
            $role->permissions()->attach($permissionIds);
        }

        return $role->load('permissions');
    }

    /**
     * Update a role
     */
    public function updateRole(int $id, array $data, array $permissionIds = null): bool
    {
        $role = $this->roleRepository->find($id);

        if (!$role) {
            return false;
        }

        $updated = $this->roleRepository->update($id, $data);

        // Sync permissions if provided
        if ($permissionIds !== null) {
            $role->permissions()->sync($permissionIds);
        }

        return $updated;
    }

    /**
     * Delete a role
     */
    public function deleteRole(int $id): bool
    {
        $role = $this->roleRepository->find($id);

        if (!$role) {
            return false;
        }

        // Detach permissions first
        $role->permissions()->detach();

        return $this->roleRepository->delete($id);
    }

    /**
     * Assign permissions to a role
     */
    public function assignPermissionsToRole(int $roleId, array $permissionIds): bool
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return false;
        }

        $role->permissions()->syncWithoutDetaching($permissionIds);

        return true;
    }

    /**
     * Remove permissions from a role
     */
    public function removePermissionsFromRole(int $roleId, array $permissionIds): bool
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return false;
        }

        $role->permissions()->detach($permissionIds);

        return true;
    }

    /**
     * =============== GROUPS ===============
     */

    /**
     * Get all groups with optional column exclusion and pagination
     */
    public function getAllGroups(
        array $excludeColumns = [],
        int $perPage = null,
        array $filters = []
    ): Collection|LengthAwarePaginator {
        $query = Group::query();

        // Apply filters
        foreach ($filters as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        // Load parent relationship
        $query->with(['parent', 'children']);

        // Exclude columns
        if (!empty($excludeColumns)) {
            $allColumns = $this->groupRepository->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        // Get hierarchical tree if not paginated
        if (!$perPage) {
            return $this->buildGroupTree($query->get());
        }

        // Paginate
        return $query->paginate($perPage);
    }

    /**
     * Build hierarchical group tree
     */
    protected function buildGroupTree(Collection $groups): Collection
    {
        $groupDict = [];
        $tree = new Collection();

        // Store all groups in dictionary
        foreach ($groups as $group) {
            $group->children = new Collection();
            $groupDict[$group->id] = $group;
        }

        // Build tree
        foreach ($groups as $group) {
            if ($group->parent_id && isset($groupDict[$group->parent_id])) {
                $groupDict[$group->parent_id]->children->push($group);
            } else {
                $tree->push($group);
            }
        }

        return $tree;
    }

    /**
     * Get groups by parent
     */
    public function getGroupsByParent(
        int $parentId,
        array $excludeColumns = [],
        int $perPage = null
    ): Collection|LengthAwarePaginator {
        return $this->getAllGroups($excludeColumns, $perPage, ['parent_id' => $parentId]);
    }

    /**
     * Get system groups
     */
    public function getSystemGroups(
        array $excludeColumns = [],
        int $perPage = null
    ): Collection|LengthAwarePaginator {
        return $this->getAllGroups($excludeColumns, $perPage, ['is_system' => true]);
    }

    /**
     * Create a new group
     */
    public function createGroup(array $data): Group
    {
        // Ensure slug is generated if not provided
        if (!isset($data['slug']) && isset($data['name'])) {
            $data['slug'] = \Illuminate\Support\Str::slug($data['name']);
        }

        return $this->groupRepository->create($data);
    }

    /**
     * Update a group
     */
    public function updateGroup(int $id, array $data): bool
    {
        // Prevent circular reference
        if (isset($data['parent_id']) && $data['parent_id'] == $id) {
            throw new \InvalidArgumentException('Group cannot be its own parent');
        }

        return $this->groupRepository->update($id, $data);
    }

    /**
     * Delete a group
     */
    public function deleteGroup(int $id, bool $withChildren = false): bool
    {
        $group = $this->groupRepository->find($id);

        if (!$group) {
            return false;
        }

        if ($withChildren) {
            // Delete children recursively
            $this->deleteGroupChildren($group);
        } else {
            // Orphan the children
            Group::where('parent_id', $id)->update(['parent_id' => null]);
        }

        return $this->groupRepository->delete($id);
    }

    /**
     * Recursively delete group children
     */
    protected function deleteGroupChildren(Group $group): void
    {
        foreach ($group->children as $child) {
            $this->deleteGroupChildren($child);
            $child->delete();
        }
    }

    /**
     * Get group tree (hierarchical)
     */
    public function getGroupTree(array $excludeColumns = []): Collection
    {
        $query = Group::query()->with('children');

        if (!empty($excludeColumns)) {
            $allColumns = $this->groupRepository->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        $groups = $query->get();

        return $this->buildGroupTree($groups);
    }

    /**
     * Get roles for a specific group
     */
    public function getRolesForGroup(int $groupId, array $excludeColumns = [], int $perPage = null): Collection|LengthAwarePaginator
    {
        return $this->getRolesByGroup($groupId, $excludeColumns, $perPage);
    }

    /**
     * Get permissions for a specific role
     */
    public function getPermissionsForRole(int $roleId, array $excludeColumns = [], int $perPage = null): Collection|LengthAwarePaginator
    {
        $role = $this->roleRepository->find($roleId);

        if (!$role) {
            return new Collection();
        }

        $query = $role->permissions();

        if (!empty($excludeColumns)) {
            $allColumns = $this->permissionRepository->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        if ($perPage) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    // Add audit logging methods to RbacService
    public function logPermissionChange(string $event, Permission $permission, array $oldData = null): void
    {
        $this->auditLogService->logManualEvent(
            $event,
            "Permission '{$permission->name}' {$event}",
            $permission,
            auth()->user()
        );
    }

    public function logRoleChange(string $event, Role $role, array $oldData = null): void
    {
        $this->auditLogService->logManualEvent(
            $event,
            "Role '{$role->name}' {$event}",
            $role,
            auth()->user()
        );
    }

    public function logGroupChange(string $event, Group $group, array $oldData = null): void
    {
        $this->auditLogService->logManualEvent(
            $event,
            "Group '{$group->name}' {$event}",
            $group,
            auth()->user()
        );
    }
}