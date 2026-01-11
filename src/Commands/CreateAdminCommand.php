<?php

// src/Commands/CreateAdminCommand.php
namespace LaravelAdRbac\Commands;

use Illuminate\Console\Command;
use LaravelAdRbac\Models\Employee;
use LaravelAdRbac\Models\Permission;
use LaravelAdRbac\Models\Role;
use LaravelAdRbac\Services\RbacService;

class CreateAdminCommand extends Command
{
    protected $signature = 'ad-rbac:create-admin 
                            {username? : Username/email for admin user}
                            {--role=system-administrator : Role slug to assign}
                            {--password=admin123 : Initial password}
                            {--grant-all-permissions : Grant all permissions to the role}
                            {--force : Force recreation of user/role}
                            {--skip-role-creation : Skip role creation if not exists}';

    protected $description = 'Create an admin user with specified role';

    public function handle()
    {
        $username = $this->argument('username') ?? "emp20260003@eagle.com";
        $roleSlug = $this->option('role') ?? "system-administrator";

        try {
            $this->info("Setting up admin user '{$username}' with role '{$roleSlug}'...");

            // 1. Find or create employee using firstOrCreate to avoid duplicate queries
            $employee = $this->findOrCreateEmployee($username);

            // 2. Find or create role using firstOrCreate
            $role = $this->findOrCreateRole($roleSlug);

            // 3. Assign role to employee (sync without detaching existing roles)
            $this->assignRoleToEmployee($employee, $role);

            // 4. Optionally grant all permissions to admin role
            if ($this->option('grant-all-permissions')) {
                $this->grantAllPermissionsToRole($role);
            }

            // 5. Display success message
            $this->displaySuccessMessage($employee, $role);

            return 0; // Success

        } catch (\Exception $e) {
            $this->error("Failed to setup admin user: " . $e->getMessage());
            $this->error("Error details: " . $e->getFile() . ":" . $e->getLine());

            return 1; // Error
        }
    }

    /**
     * Find or create employee record
     */
    private function findOrCreateEmployee(string $username): Employee
    {
        return Employee::firstOrCreate(
            ['ad_username' => $username],
            [
                'employee_id' => 'EMP20260003',
                'email' => $username,
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'full_name' => 'System Administrator',
                'department' => 'IT',
                'position' => 'System Administrator',
                'is_active' => true,
                'password' => bcrypt($this->option('password') ?? 'admin123'),
                'password_hash' => bcrypt($this->option('password') ?? 'admin123'),
                'last_login_at' => now(),
                'ad_sync_at' => now(),
            ]
        );
    }

    /**
     * Find or create admin role
     */
    private function findOrCreateRole(string $roleSlug): Role
    {
        $roleName = match ($roleSlug) {
            'system-administrator' => 'System Administrator',
            'super-admin' => 'Super Administrator',
            default => ucwords(str_replace('-', ' ', $roleSlug)),
        };

        return Role::firstOrCreate(
            ['slug' => $roleSlug],
            [
                'name' => $roleName,
                'description' => "Full system access with all permissions. Can manage users, roles, permissions, and system settings.",
                'group_id' => null,
                'is_system' => true,
            ]
        );
    }

    /**
     * Assign role to employee
     */
    private function assignRoleToEmployee(Employee $employee, Role $role): void
    {
        // Temporarily use direct insert into assignments table
        \DB::table('assignments')->insert([
            'employee_id' => $employee->id,
            'assignable_type' => 'App\Models\Role', // Use full class name
            'assignable_id' => $role->id,
            'assignment_reason' => 'System Administrator Setup via Command',
            'assigned_by' => 0, // 0 for system
            'assigned_at' => now(),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Also maintain the roles relationship if it exists
        if (method_exists($employee, 'roles')) {
            try {
                $employee->roles()->syncWithoutDetaching([$role->id]);
            } catch (\Exception $e) {
                // Ignore if this fails
            }
        }

        $this->info("✓ Role '{$role->name}' assigned to '{$employee->ad_username}'");

        // Log the assignment
        \Log::info("Assigned role '{$role->name}' to employee '{$employee->ad_username}'", [
            'employee_id' => $employee->id,
            'role_id' => $role->id,
            'assigned_at' => now(),
        ]);
    }

    /**
     * Grant all permissions to role
     */
    private function grantAllPermissionsToRole(Role $role): void
    {
        if (!\Schema::hasTable('permissions')) {
            $this->warn("Permissions table does not exist. Skipping permission assignment.");
            return;
        }

        $permissions = Permission::all();

        if ($permissions->isEmpty()) {
            $this->warn("No permissions found in database. Creating default permissions...");
            $this->createDefaultPermissions($role);
        } else {
            $role->permissions()->sync($permissions->pluck('id')->toArray());
            $this->info("✓ Granted all {$permissions->count()} permissions to '{$role->name}' role");
        }
    }

    /**
     * Create default system permissions
     */
    private function createDefaultPermissions(Role $role): void
    {
        $permissions = [
            // User Management
            ['name' => 'View Users', 'slug' => 'employees.view', 'module' => 'User Management'],
            ['name' => 'Create Users', 'slug' => 'employees.create', 'module' => 'User Management'],
            ['name' => 'Edit Users', 'slug' => 'employees.edit', 'module' => 'User Management'],
            ['name' => 'Delete Users', 'slug' => 'employees.delete', 'module' => 'User Management'],
            ['name' => 'Assign Groups', 'slug' => 'employees.assign-group', 'module' => 'User Management'],
            ['name' => 'Assign Roles', 'slug' => 'assignments.assign-role', 'module' => 'User Management'],
            ['name' => 'Assign Permissions', 'slug' => 'assignments.assign-permission', 'module' => 'User Management'],



            // Role Management
            ['name' => 'View Roles', 'slug' => 'roles.view', 'module' => 'Role Management'],
            ['name' => 'Create Roles', 'slug' => 'roles.create', 'module' => 'Role Management'],
            ['name' => 'Edit Roles', 'slug' => 'roles.edit', 'module' => 'Role Management'],
            ['name' => 'Delete Roles', 'slug' => 'roles.delete', 'module' => 'Role Management'],

            // Group Management
            ['name' => 'View Groups', 'slug' => 'groups.view', 'module' => 'Group Management'],
            ['name' => 'Create Groups', 'slug' => 'groups.create', 'module' => 'Group Management'],
            ['name' => 'Edit Groups', 'slug' => 'groups.edit', 'module' => 'Group Management'],
            ['name' => 'Delete Groups', 'slug' => 'groups.delete', 'module' => 'Group Management'],

            // Permissions Management
            ['name' => 'View Permissions', 'slug' => 'permissions.view', 'module' => 'Permissions Management'],
            ['name' => 'Create Permissions', 'slug' => 'permissions.create', 'module' => 'Permissions Management'],
            ['name' => 'Edit Permissions', 'slug' => 'permissions.edit', 'module' => 'Permissions Management'],
            ['name' => 'Delete Permissions', 'slug' => 'permissions.delete', 'module' => 'Permissions Management'],

            // System Access
            ['name' => 'Access Dashboard', 'slug' => 'dashboard.access', 'module' => 'System'],
            ['name' => 'Manage Settings', 'slug' => 'settings.manage', 'module' => 'System'],
        ];

        $permissionIds = [];
        foreach ($permissions as $permission) {
            $created = Permission::firstOrCreate(
                ['slug' => $permission['slug']],
                [
                    'name' => $permission['name'],
                    'description' => $permission['name'],
                    'module' => $permission['module'],
                ]
            );
            $permissionIds[] = $created->id;
        }

        $role->permissions()->sync($permissionIds);
        $this->info("✓ Created and assigned " . count($permissions) . " default permissions");
    }

    /**
     * Display success message with details
     */
    private function displaySuccessMessage(Employee $employee, Role $role): void
    {
        $this->newLine();
        $this->line(str_repeat('=', 50));
        $this->info("✅ ADMIN USER SETUP COMPLETED SUCCESSFULLY");
        $this->line(str_repeat('=', 50));

        $this->table(
            ['Field', 'Value'],
            [
                ['Username', $employee->ad_username],
                ['Email', $employee->email],
                ['Employee ID', $employee->employee_id],
                ['Full Name', $employee->full_name ?? "{$employee->first_name} {$employee->last_name}"],
                ['Department', $employee->department],
                ['Position', $employee->position],
                ['Status', $employee->is_active ? '✅ Active' : '❌ Inactive'],
                ['Assigned Role', $role->name],
                ['Role Type', $role->is_system ? '🔐 System Role' : '👥 Custom Role'],
                ['Permissions Count', $role->permissions->count()],
            ]
        );

        // Show password if newly created
        if ($employee->wasRecentlyCreated) {
            $password = $this->option('password') ?? 'admin123';
            $this->warn("⚠️  Default password: {$password}");
            $this->warn("⚠️  Please change the password on first login!");
        }

        $this->newLine();
        $this->line("Next steps:");
        $this->line("1. Login with email: <comment>{$employee->email}</comment>");
        $this->line("2. Test permissions and role assignments");
        $this->line("3. Review and modify permissions as needed");
        $this->newLine();
    }
}