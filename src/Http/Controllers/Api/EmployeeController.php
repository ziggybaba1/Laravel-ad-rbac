<?php

// src/Http/Controllers/Api/EmployeeController.php
namespace LaravelAdRbac\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LaravelAdRbac\Models\Employee;
use LaravelAdRbac\Models\Role;
use LaravelAdRbac\Http\Resources\EmployeeResource;
use LaravelAdRbac\Http\Resources\RoleResource;
use Illuminate\Support\Facades\DB;

class EmployeeController extends BaseApiController
{
    /**
     * @OA\Get(
     *     path="/api/ad-rbac/employees",
     *     summary="List employees",
     *     tags={"Employees"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search term",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee list",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Employee")),
     *             @OA\Property(property="pagination", ref="#/components/schemas/Pagination")
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $this->authorizeApi('employees.read');

        $query = Employee::query();

        // Search
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('ad_username', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            });
        }

        // Filter by department
        if ($department = $request->get('department')) {
            $query->where('department', $department);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by role
        if ($roleId = $request->get('role_id')) {
            $query->whereHas('roles', function ($q) use ($roleId) {
                $q->where('id', $roleId);
            });
        }

        // Order by
        $orderBy = $request->get('order_by', 'last_name');
        $orderDir = $request->get('order_dir', 'asc');
        $query->orderBy($orderBy, $orderDir);

        // Eager load
        $query->with(['roles', 'permissions']);

        $perPage = $request->get('per_page', config('ad-rbac.api.per_page', 20));
        $employees = $query->paginate($perPage);

        return $this->paginated($employees, 'Employees retrieved successfully');
    }

    /**
     * @OA\Get(
     *     path="/api/ad-rbac/employees/{id}",
     *     summary="Get employee details",
     *     tags={"Employees"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Employee ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Employee")
     *         )
     *     )
     * )
     */
    public function show($id)
    {
        $this->authorizeApi('employees.read');

        $employee = Employee::with(['roles', 'permissions', 'groups'])->findOrFail($id);

        return $this->success(
            new EmployeeResource($employee),
            'Employee retrieved successfully'
        );
    }

    /**
     * @OA\Put(
     *     path="/api/ad-rbac/employees/{id}",
     *     summary="Update employee",
     *     tags={"Employees"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Employee ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="department", type="string"),
     *             @OA\Property(property="position", type="string"),
     *             @OA\Property(property="is_active", type="boolean")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee updated"
     *     )
     * )
     */
    public function update(Request $request, $id)
    {
        $this->authorizeApi('employees.update');

        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'department' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $employee->update($validated);

        return $this->success(
            new EmployeeResource($employee->fresh()),
            'Employee updated successfully'
        );
    }

    /**
     * @OA\Get(
     *     path="/api/ad-rbac/employees/{id}/roles",
     *     summary="Get employee's roles",
     *     tags={"Employees"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Employee ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Employee roles"
     *     )
     * )
     */
    public function roles($id)
    {
        $this->authorizeApi('employees.read');

        $employee = Employee::with('roles')->findOrFail($id);

        return $this->success([
            'employee_id' => $employee->id,
            'roles' => RoleResource::collection($employee->roles),
            'available_roles' => RoleResource::collection(Role::whereNotIn('id', $employee->roles->pluck('id'))->get()),
        ], 'Employee roles retrieved successfully');
    }

    /**
     * @OA\Post(
     *     path="/api/ad-rbac/employees/{id}/roles",
     *     summary="Assign roles to employee",
     *     tags={"Employees"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Employee ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"role_ids"},
     *             @OA\Property(property="role_ids", type="array", @OA\Items(type="integer")),
     *             @OA\Property(property="mode", type="string", enum={"sync", "attach", "detach"}, example="sync")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Roles assigned"
     *     )
     * )
     */
    public function assignRoles(Request $request, $id)
    {
        $this->authorizeApi('employees.manage-roles');

        $employee = Employee::findOrFail($id);

        $validated = $request->validate([
            'role_ids' => 'required|array',
            'role_ids.*' => 'exists:roles,id',
            'mode' => 'in:sync,attach,detach',
        ]);

        $mode = $validated['mode'] ?? 'sync';

        DB::transaction(function () use ($employee, $validated, $mode) {
            switch ($mode) {
                case 'sync':
                    $employee->roles()->sync($validated['role_ids']);
                    break;
                case 'attach':
                    $employee->roles()->attach($validated['role_ids']);
                    break;
                case 'detach':
                    $employee->roles()->detach($validated['role_ids']);
                    break;
            }

            // Clear cached permissions
            $employee->clearPermissionCache();
        });

        return $this->success(
            new EmployeeResource($employee->fresh()->load('roles')),
            'Roles assigned successfully'
        );
    }
}