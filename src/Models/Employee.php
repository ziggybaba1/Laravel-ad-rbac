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

    public function syncRoles(array $roleIds, $reason = null, $assignedBy = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();

        foreach ($roleIds as $roleId) {
            $role = Role::find($roleId);

            if ($role && !$this->hasAssignment($role)) {
                $this->assign($role, $reason, null, $assignedBy);
            }
        }

        return $this;
    }

    /**
     * Sync roles with detaching (remove roles not in array)
     */
    public function syncRolesWithDetach(array $roleIds, $reason = null, $assignedBy = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();

        // Get current active role assignments
        $currentRoleIds = $this->roleAssignments()->pluck('assignable_id')->toArray();

        // Roles to remove
        $rolesToRemove = array_diff($currentRoleIds, $roleIds);
        foreach ($rolesToRemove as $roleId) {
            $role = Role::find($roleId);
            if ($role) {
                $this->unassign($role, $reason);
            }
        }

        // Roles to add
        $rolesToAdd = array_diff($roleIds, $currentRoleIds);
        foreach ($rolesToAdd as $roleId) {
            $role = Role::find($roleId);
            if ($role && !$this->hasAssignment($role)) {
                $this->assign($role, $reason, null, $assignedBy);
            }
        }

        return $this;
    }

    /**
     * Sync groups without detaching existing ones
     */
    public function syncGroups(array $groupIds, $reason = null, $assignedBy = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();

        foreach ($groupIds as $groupId) {
            $group = Group::find($groupId);

            if ($group && !$this->hasAssignment($group)) {
                $this->assign($group, $reason, null, $assignedBy);
            }
        }

        return $this;
    }

    /**
     * Sync permissions without detaching existing ones
     */
    public function syncPermissions(array $permissionIds, $reason = null, $assignedBy = null)
    {
        $assignedBy = $assignedBy ?: auth()->id();

        foreach ($permissionIds as $permissionId) {
            $permission = Permission::find($permissionId);

            if ($permission && !$this->hasAssignment($permission)) {
                $this->assign($permission, $reason, null, $assignedBy);
            }
        }

        return $this;
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
}