<?php

namespace LaravelAdRbac\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use LaravelAdRbac\Traits\HasPermissions;
use Illuminate\Notifications\Notifiable;

class Employee extends Authenticatable
{
    use HasFactory, Notifiable, HasPermissions;

    protected $fillable = [
        'ad_username',
        'employee_id',
        'email',
        'first_name',
        'last_name',
        'department',
        'position',
        'is_active',
        'last_login_at',
        'ad_sync_at',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'ad_sync_at' => 'datetime',
    ];


    /**
     * Relationship: Employee has many assignments
     */
    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }



    /**
     * Relationship: Employee's group assignments
     */
    public function groupAssignments()
    {
        return $this->assignments()->groups()->active();
    }

    /**
     * Relationship: Employee's role assignments
     */
    public function roleAssignments()
    {
        return $this->assignments()->roles()->active();
    }

    /**
     * Relationship: Employee's permission assignments
     */
    public function permissionAssignments()
    {
        return $this->assignments()->permissions()->active();
    }

    /**
     * Relationship: Employee's groups (through assignments)
     */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'assignments', 'employee_id', 'assignable_id')
            ->wherePivot('assignable_type', 'group')
            ->wherePivot('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->withTimestamps()
            ->withPivot(['assignment_reason', 'assigned_by', 'assigned_at', 'expires_at']);
    }

    /**
     * Relationship: Employee's roles (through assignments)
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'assignments', 'employee_id', 'assignable_id')
            ->wherePivot('assignable_type', 'role')
            ->wherePivot('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->withTimestamps()
            ->withPivot(['assignment_reason', 'assigned_by', 'assigned_at', 'expires_at']);
    }

    /**
     * Relationship: Employee's direct permissions (through assignments)
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'assignments', 'employee_id', 'assignable_id')
            ->wherePivot('assignable_type', 'permission')
            ->wherePivot('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->withTimestamps()
            ->withPivot(['assignment_reason', 'assigned_by', 'assigned_at', 'expires_at']);
    }

    /**
     * Get all permissions for the employee (through roles, groups, and direct)
     */
    public function getAllPermissions()
    {
        $cacheKey = "employee_permissions_{$this->id}";

        return cache()->remember($cacheKey, 3600, function () {
            // Start with direct permissions
            $permissions = $this->permissions;

            // Add permissions from roles
            foreach ($this->roles as $role) {
                $permissions = $permissions->merge($role->permissions);
            }

            // Add permissions from groups through roles
            foreach ($this->groups as $group) {
                foreach ($group->roles as $role) {
                    $permissions = $permissions->merge($role->permissions);
                }
            }

            return $permissions->unique('id');
        });
    }

    /**
     * Assign a group, role, or permission to employee
     */
    public function assign($assignable, string $reason = null, $expiresAt = null, $assignedBy = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();

        // Determine assignable type
        $type = match (get_class($assignable)) {
            Group::class => 'group',
            Role::class => 'role',
            Permission::class => 'permission',
            default => throw new \InvalidArgumentException('Invalid assignable type'),
        };

        // Check if assignment already exists and is active
        $existing = $this->assignments()
            ->where('assignable_id', $assignable->id)
            ->where('assignable_type', $type)
            ->active()
            ->first();

        if ($existing) {
            throw new \Exception("Active assignment already exists for this {$type}");
        }

        // Deactivate any existing expired assignment
        $this->assignments()
            ->where('assignable_id', $assignable->id)
            ->where('assignable_type', $type)
            ->where('is_active', true)
            ->where('expires_at', '<=', now())
            ->update(['is_active' => false]);

        // Create new assignment
        $assignment = Assignment::create([
            'employee_id' => $this->id,
            'assignable_id' => $assignable->id,
            'assignable_type' => $type,
            'assignment_reason' => $reason,
            'assigned_by' => $assignedBy,
            'assigned_at' => now(),
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        $assignment->logHistory('created', [
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'assigned_by' => $assignedBy,
        ]);

        // Clear permission cache
        $this->clearPermissionCache();

        return $assignment;
    }

    /**
     * Remove assignment
     */
    public function unassign($assignable, string $reason = null)
    {
        $type = match (get_class($assignable)) {
            Group::class => 'group',
            Role::class => 'role',
            Permission::class => 'permission',
            default => throw new \InvalidArgumentException('Invalid assignable type'),
        };

        $assignment = $this->assignments()
            ->where('assignable_id', $assignable->id)
            ->where('assignable_type', $type)
            ->active()
            ->first();

        if ($assignment) {
            $assignment->deactivate($reason);
            $this->clearPermissionCache();
        }

        return $assignment;
    }

    /**
     * Check if employee has a specific assignment
     */
    public function hasAssignment($assignable): bool
    {
        $type = match (get_class($assignable)) {
            Group::class => 'group',
            Role::class => 'role',
            Permission::class => 'permission',
            default => throw new \InvalidArgumentException('Invalid assignable type'),
        };

        return $this->assignments()
            ->where('assignable_id', $assignable->id)
            ->where('assignable_type', $type)
            ->active()
            ->exists();
    }

    /**
     * Get all active assignments with details
     */
    public function getAllAssignments()
    {
        return $this->assignments()
            ->with(['assignable', 'assigner'])
            ->active()
            ->get()
            ->groupBy('assignable_type');
    }

    /**
     * Clear permission cache
     */
    public function clearPermissionCache()
    {
        $cacheKey = "employee_permissions_{$this->id}";
        cache()->forget($cacheKey);
    }

    /**
     * Get the employee's full name
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function syncRoles(array $roleIds, string $reason = null, $assignedBy = null, $expiresAt = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();
        $addedRoles = [];

        foreach ($roleIds as $roleId) {
            $role = Role::find($roleId);

            if ($role && !$this->hasAssignment($role)) {
                try {
                    $assignment = $this->assign($role, $reason, $expiresAt, $assignedBy);
                    $addedRoles[] = $role;
                } catch (\Exception $e) {
                    // Log the error but continue with other roles
                    \Log::warning("Failed to assign role {$roleId} to employee {$this->id}: " . $e->getMessage());
                }
            }
        }

        return $addedRoles;
    }

    /**
     * Sync roles with detaching (remove roles not in array)
     */
    public function syncRolesWithDetach(array $roleIds, string $reason = null, $assignedBy = null, $expiresAt = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();

        // Get current active role assignments
        $currentRoleIds = $this->roleAssignments()->pluck('assignable_id')->toArray();

        // Roles to remove
        $rolesToRemove = array_diff($currentRoleIds, $roleIds);
        foreach ($rolesToRemove as $roleId) {
            $role = Role::find($roleId);
            if ($role) {
                $this->unassign($role, $reason ?: 'Role sync removal');
            }
        }

        // Roles to add
        $rolesToAdd = array_diff($roleIds, $currentRoleIds);
        $addedRoles = [];
        foreach ($rolesToAdd as $roleId) {
            $role = Role::find($roleId);
            if ($role) {
                try {
                    $assignment = $this->assign($role, $reason, $expiresAt, $assignedBy);
                    $addedRoles[] = $role;
                } catch (\Exception $e) {
                    \Log::warning("Failed to assign role {$roleId} to employee {$this->id}: " . $e->getMessage());
                }
            }
        }

        return [
            'added' => $addedRoles,
            'removed' => $rolesToRemove
        ];
    }

    /**
     * Sync groups without detaching existing ones
     */
    public function syncGroups(array $groupIds, string $reason = null, $assignedBy = null, $expiresAt = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();
        $addedGroups = [];

        foreach ($groupIds as $groupId) {
            $group = Group::find($groupId);

            if ($group && !$this->hasAssignment($group)) {
                try {
                    $assignment = $this->assign($group, $reason, $expiresAt, $assignedBy);
                    $addedGroups[] = $group;
                } catch (\Exception $e) {
                    \Log::warning("Failed to assign group {$groupId} to employee {$this->id}: " . $e->getMessage());
                }
            }
        }

        return $addedGroups;
    }

    /**
     * Sync groups with detaching (remove groups not in array)
     */
    public function syncGroupsWithDetach(array $groupIds, string $reason = null, $assignedBy = null, $expiresAt = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();

        // Get current active group assignments
        $currentGroupIds = $this->groupAssignments()->pluck('assignable_id')->toArray();

        // Groups to remove
        $groupsToRemove = array_diff($currentGroupIds, $groupIds);
        foreach ($groupsToRemove as $groupId) {
            $group = Group::find($groupId);
            if ($group) {
                $this->unassign($group, $reason ?: 'Group sync removal');
            }
        }

        // Groups to add
        $groupsToAdd = array_diff($groupIds, $currentGroupIds);
        $addedGroups = [];
        foreach ($groupsToAdd as $groupId) {
            $group = Group::find($groupId);
            if ($group) {
                try {
                    $assignment = $this->assign($group, $reason, $expiresAt, $assignedBy);
                    $addedGroups[] = $group;
                } catch (\Exception $e) {
                    \Log::warning("Failed to assign group {$groupId} to employee {$this->id}: " . $e->getMessage());
                }
            }
        }

        return [
            'added' => $addedGroups,
            'removed' => $groupsToRemove
        ];
    }

    /**
     * Sync permissions without detaching existing ones
     */
    public function syncPermissions(array $permissionIds, string $reason = null, $assignedBy = null, $expiresAt = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();
        $addedPermissions = [];

        foreach ($permissionIds as $permissionId) {
            $permission = Permission::find($permissionId);

            if ($permission && !$this->hasAssignment($permission)) {
                try {
                    $assignment = $this->assign($permission, $reason, $expiresAt, $assignedBy);
                    $addedPermissions[] = $permission;
                } catch (\Exception $e) {
                    \Log::warning("Failed to assign permission {$permissionId} to employee {$this->id}: " . $e->getMessage());
                }
            }
        }

        return $addedPermissions;
    }

    /**
     * Bulk sync for multiple employees
     * This is useful when you need to assign the same roles/groups to multiple employees
     */
    public static function bulkSyncRoles(array $employeeIds, array $roleIds, string $reason = null, $assignedBy = null, $expiresAt = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();
        $results = [
            'success' => [],
            'errors' => []
        ];

        foreach ($employeeIds as $employeeId) {
            $employee = self::find($employeeId);
            if ($employee) {
                try {
                    $addedRoles = $employee->syncRoles($roleIds, $reason, $assignedBy, $expiresAt);
                    $results['success'][$employeeId] = [
                        'employee' => $employee->full_name,
                        'added_roles' => count($addedRoles)
                    ];
                } catch (\Exception $e) {
                    $results['errors'][$employeeId] = $e->getMessage();
                }
            } else {
                $results['errors'][$employeeId] = 'Employee not found';
            }
        }

        return $results;
    }

    /**
     * Bulk sync groups for multiple employees
     */
    public static function bulkSyncGroups(array $employeeIds, array $groupIds, string $reason = null, $assignedBy = null, $expiresAt = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();
        $results = [
            'success' => [],
            'errors' => []
        ];

        foreach ($employeeIds as $employeeId) {
            $employee = self::find($employeeId);
            if ($employee) {
                try {
                    $addedGroups = $employee->syncGroups($groupIds, $reason, $assignedBy, $expiresAt);
                    $results['success'][$employeeId] = [
                        'employee' => $employee->full_name,
                        'added_groups' => count($addedGroups)
                    ];
                } catch (\Exception $e) {
                    $results['errors'][$employeeId] = $e->getMessage();
                }
            } else {
                $results['errors'][$employeeId] = 'Employee not found';
            }
        }

        return $results;
    }

    /**
     * Get all available roles that employee doesn't have
     */
    public function getAvailableRoles()
    {
        $currentRoleIds = $this->roleAssignments()->pluck('assignable_id')->toArray();

        return Role::whereNotIn('id', $currentRoleIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get all available groups that employee doesn't have
     */
    public function getAvailableGroups()
    {
        $currentGroupIds = $this->groupAssignments()->pluck('assignable_id')->toArray();

        return Group::whereNotIn('id', $currentGroupIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get all available permissions that employee doesn't have (directly)
     */
    public function getAvailablePermissions()
    {
        $currentPermissionIds = $this->permissionAssignments()->pluck('assignable_id')->toArray();

        return Permission::whereNotIn('id', $currentPermissionIds)
            ->orderBy('module')
            ->orderBy('name')
            ->get();
    }
}