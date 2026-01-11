<?php

// src/Http/Resources/GroupResource.php
namespace LaravelAdRbac\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'parent_id' => $this->parent_id,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Relationships
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'parent' => new GroupResource($this->whenLoaded('parent')),
            'children' => GroupResource::collection($this->whenLoaded('children')),
            'employees' => EmployeeResource::collection($this->whenLoaded('employees')),

            // Counts
            'roles_count' => $this->whenLoaded('roles', $this->roles->count()),
            'children_count' => $this->whenLoaded('children', $this->children->count()),
            'employees_count' => $this->whenLoaded('employees', $this->employees->count()),

            // Tree structure
            'path' => $this->when($this->relationLoaded('ancestors'), function () {
                return $this->ancestors->pluck('name')->join(' > ') . ' > ' . $this->name;
            }),

            // Links
            'links' => [
                'self' => route('api.ad-rbac.groups.show', $this->id),
                'roles' => route('api.ad-rbac.groups.roles', $this->id),
                'employees' => route('api.ad-rbac.groups.employees', $this->id),
            ],
        ];
    }
}