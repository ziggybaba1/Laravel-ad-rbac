<?php

// src/Http/Resources/EmployeeResource.php
namespace LaravelAdRbac\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'ad_username' => $this->ad_username,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'department' => $this->department,
            'position' => $this->position,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toISOString(),
            'ad_sync_at' => $this->ad_sync_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Relationships (when loaded)
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'groups' => GroupResource::collection($this->whenLoaded('groups')),

            // Counts
            'roles_count' => $this->whenLoaded('roles', $this->roles->count()),
            'permissions_count' => $this->whenLoaded('permissions', $this->permissions->count()),

            // Links
            'links' => [
                'self' => route('api.ad-rbac.employees.show', $this->id),
                'roles' => route('api.ad-rbac.employees.roles', $this->id),
                'permissions' => route('api.ad-rbac.employees.permissions', $this->id),
            ],
        ];
    }
}