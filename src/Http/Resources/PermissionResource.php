<?php

// src/Http/Resources/PermissionResource.php
namespace LaravelAdRbac\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PermissionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'action' => $this->action,
            'module' => $this->module,
            'module_name' => class_basename($this->module),
            'description' => $this->description,
            'category' => $this->category,
            'is_system' => $this->is_system,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Relationships
            'roles' => RoleResource::collection($this->whenLoaded('roles')),

            // Links
            'links' => [
                'self' => route('api.ad-rbac.permissions.show', $this->id),
                'roles' => route('api.ad-rbac.permissions.roles', $this->id),
            ],
        ];
    }
}