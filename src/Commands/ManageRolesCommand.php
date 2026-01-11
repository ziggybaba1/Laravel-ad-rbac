<?php


// src/Commands/ManageRolesCommand.php
namespace LaravelAdRbac\Commands;

use Illuminate\Console\Command;
use LaravelAdRbac\Models\Role;
use LaravelAdRbac\Models\Permission;
use LaravelAdRbac\Models\Group;

class ManageRolesCommand extends Command
{
    protected $signature = 'ad-rbac:manage-roles
                            {--list : List all roles and permissions}
                            {--create : Create a new role}
                            {--edit= : Edit existing role}
                            {--assign-permissions : Assign permissions to role}
                            {--assign-to-group : Assign role to group}';

    protected $description = 'Manage roles and permissions interactively';

    public function handle()
    {
        if ($this->option('list')) {
            $this->listRoles();
        } elseif ($this->option('create')) {
            $this->createRole();
        } elseif ($roleSlug = $this->option('edit')) {
            $this->editRole($roleSlug);
        } elseif ($this->option('assign-permissions')) {
            $this->assignPermissions();
        } elseif ($this->option('assign-to-group')) {
            $this->assignToGroup();
        } else {
            $this->interactiveMenu();
        }
    }

    protected function interactiveMenu(): void
    {
        $choice = $this->choice('What would you like to do?', [
            'List roles and permissions',
            'Create new role',
            'Edit existing role',
            'Assign permissions to role',
            'Assign role to group',
            'Exit'
        ], 0);

        switch ($choice) {
            case 'List roles and permissions':
                $this->listRoles();
                break;
            case 'Create new role':
                $this->createRole();
                break;
            case 'Edit existing role':
                $roleSlug = $this->ask('Enter role slug to edit');
                $this->editRole($roleSlug);
                break;
            case 'Assign permissions to role':
                $this->assignPermissions();
                break;
            case 'Assign role to group':
                $this->assignToGroup();
                break;
        }

        if ($this->confirm('Perform another action?', true)) {
            $this->interactiveMenu();
        }
    }

    protected function listRoles(): void
    {
        $roles = Role::with(['permissions', 'group'])->get();

        $this->info('📋 ROLES AND PERMISSIONS');
        $this->newLine();

        foreach ($roles as $role) {
            $this->info("🔹 {$role->name} ({$role->slug})");
            $this->line("   Description: {$role->description}");
            $this->line("   Group: " . ($role->group ? $role->group->name : 'None'));
            $this->line("   Permissions: " . $role->permissions->count());

            if ($role->permissions->isNotEmpty() && $this->confirm('Show permissions for this role?', false)) {
                $this->table(
                    ['Slug', 'Action', 'Module'],
                    $role->permissions->map(fn($p) => [$p->slug, $p->action, class_basename($p->module)])
                );
            }

            $this->newLine();
        }
    }

    // ... (other methods for create, edit, assign)
}