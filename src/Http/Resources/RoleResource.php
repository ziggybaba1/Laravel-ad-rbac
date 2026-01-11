<?php

// src/Http/Resources/RoleResource.php
namespace LaravelAdRbac\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Relationships
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions')),
            'employees' => EmployeeResource::collection($this->whenLoaded('employees')),
            'group' => new GroupResource($this->whenLoaded('group')),

            // Counts
            'permissions_count' => $this->whenLoaded('permissions', $this->permissions->count()),
            'employees_count' => $this->whenLoaded('employees', $this->employees->count()),

            // Links
            'links' => [
                'self' => route('api.ad-rbac.roles.show', $this->id),
                'permissions' => route('api.ad-rbac.roles.permissions', $this->id),
                'employees' => route('api.ad-rbac.roles.employees', $this->id),
            ],
        ];
    }
}