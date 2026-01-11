<?php

// src/Http/Controllers/Api/GroupController.php
namespace LaravelAdRbac\Http\Controllers\Api;

use Illuminate\Http\Request;
use LaravelAdRbac\Models\Group;
use LaravelAdRbac\Http\Resources\GroupResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GroupController extends BaseApiController
{
    public function index(Request $request)
    {
        $this->authorizeApi('groups.read');

        $query = Group::with(['roles', 'parent', 'children']);

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by parent
        if ($parentId = $request->get('parent_id')) {
            if ($parentId === 'null') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $parentId);
            }
        }

        // Get tree structure
        if ($request->boolean('tree', false)) {
            $roots = Group::whereNull('parent_id')
                ->with([
                    'children' => function ($query) {
                        $query->with('children');
                    }
                ])
                ->orderBy('name')
                ->get();

            return $this->success(
                GroupResource::collection($roots),
                'Group tree retrieved successfully'
            );
        }

        // Order by
        $orderBy = $request->get('order_by', 'name');
        $orderDir = $request->get('order_dir', 'asc');
        $query->orderBy($orderBy, $orderDir);

        $perPage = $request->get('per_page', config('ad-rbac.api.per_page', 20));
        $groups = $query->paginate($perPage);

        return $this->paginated($groups, 'Groups retrieved successfully');
    }

    public function store(Request $request)
    {
        $this->authorizeApi('groups.create');

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:groups,name',
            'slug' => 'nullable|string|max:100|unique:groups,slug',
            'description' => 'nullable|string|max:255',
            'parent_id' => 'nullable|exists:groups,id',
        ]);

        // Generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Check for circular reference
        if ($validated['parent_id']) {
            $this->validateNoCircularReference($validated['parent_id'], null);
        }

        $group = Group::create($validated);

        return $this->success(
            new GroupResource($group->load('parent', 'children')),
            'Group created successfully',
            201
        );
    }

    public function show($id)
    {
        $this->authorizeApi('groups.read');

        $group = Group::with(['roles', 'parent', 'children', 'employees'])->findOrFail($id);

        return $this->success(
            new GroupResource($group),
            'Group retrieved successfully'
        );
    }

    public function update(Request $request, $id)
    {
        $this->authorizeApi('groups.update');

        $group = Group::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:groups,name,' . $id,
            'slug' => 'nullable|string|max:100|unique:groups,slug,' . $id,
            'description' => 'nullable|string|max:255',
            'parent_id' => 'nullable|exists:groups,id',
        ]);

        // Generate slug if not provided
        if (empty($validated['slug']) && $validated['name'] !== $group->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Check for circular reference
        if ($validated['parent_id'] && $validated['parent_id'] != $group->parent_id) {
            $this->validateNoCircularReference($validated['parent_id'], $id);
        }

        $group->update($validated);

        return $this->success(
            new GroupResource($group->fresh()->load('parent', 'children')),
            'Group updated successfully'
        );
    }

    public function destroy($id)
    {
        $this->authorizeApi('groups.delete');

        $group = Group::findOrFail($id);

        // Check if group has children
        if ($group->children()->count() > 0) {
            return $this->error('Cannot delete group that has child groups', [
                'children_count' => $group->children()->count(),
            ], 422);
        }

        // Check if group has roles
        if ($group->roles()->count() > 0) {
            return $this->error('Cannot delete group that has roles assigned', [
                'roles_count' => $group->roles()->count(),
            ], 422);
        }

        // Detach all employees
        $group->employees()->detach();

        // Delete the group
        $group->delete();

        return $this->success(null, 'Group deleted successfully');
    }

    /**
     * Validate no circular reference in group hierarchy
     */
    private function validateNoCircularReference($parentId, $currentId)
    {
        $parent = Group::find($parentId);

        // Check if parent is a descendant of current group
        while ($parent) {
            if ($parent->id == $currentId) {
                abort(422, 'Circular reference detected: Group cannot be parent of its own ancestor.');
            }

            $parent = $parent->parent;
        }
    }

    public function roles($id)
    {
        $this->authorizeApi('groups.read');

        $group = Group::with('roles')->findOrFail($id);

        return $this->success([
            'group' => new GroupResource($group),
            'roles' => $group->roles,
        ], 'Group roles retrieved successfully');
    }
}