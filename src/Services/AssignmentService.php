<?php

// src/Services/AssignmentService.php
namespace LaravelAdRbac\Services;

use LaravelAdRbac\Models\Employee;
use LaravelAdRbac\Models\Group;
use LaravelAdRbac\Models\Role;
use LaravelAdRbac\Models\Permission;
use LaravelAdRbac\Models\Assignment;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    /**
     * Assign multiple items to employee
     */
    public function assignToEmployee(Employee $employee, array $assignments, string $reason = null, $assignedBy = null): array
    {
        $results = [
            'success' => [],
            'failed' => [],
            'skipped' => [],
        ];

        DB::transaction(function () use ($employee, $assignments, $reason, $assignedBy, &$results) {
            foreach ($assignments as $type => $ids) {
                if (empty($ids))
                    continue;

                $modelClass = $this->getModelClass($type);

                foreach ($ids as $id) {
                    try {
                        $assignable = $modelClass::findOrFail($id);

                        // Check if already assigned
                        if ($employee->hasAssignment($assignable)) {
                            $results['skipped'][] = [
                                'type' => $type,
                                'id' => $id,
                                'reason' => 'Already assigned',
                            ];
                            continue;
                        }

                        // Create assignment
                        $assignment = $employee->assign($assignable, $reason, null, $assignedBy);

                        $results['success'][] = [
                            'type' => $type,
                            'id' => $id,
                            'assignment_id' => $assignment->id,
                        ];

                    } catch (\Exception $e) {
                        $results['failed'][] = [
                            'type' => $type,
                            'id' => $id,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }
        });

        return $results;
    }

    /**
     * Remove assignments from employee
     */
    public function unassignFromEmployee(Employee $employee, array $assignments, string $reason = null): array
    {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($assignments as $type => $ids) {
            if (empty($ids))
                continue;

            $modelClass = $this->getModelClass($type);

            foreach ($ids as $id) {
                try {
                    $assignable = $modelClass::findOrFail($id);
                    $assignment = $employee->unassign($assignable, $reason);

                    if ($assignment) {
                        $results['success'][] = [
                            'type' => $type,
                            'id' => $id,
                            'assignment_id' => $assignment->id,
                        ];
                    } else {
                        $results['failed'][] = [
                            'type' => $type,
                            'id' => $id,
                            'error' => 'No active assignment found',
                        ];
                    }

                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'type' => $type,
                        'id' => $id,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Sync assignments (remove old, add new)
     */
    public function syncAssignments(Employee $employee, array $assignments, string $reason = null, $assignedBy = null): array
    {
        $results = [
            'added' => [],
            'removed' => [],
            'unchanged' => [],
        ];

        DB::transaction(function () use ($employee, $assignments, $reason, $assignedBy, &$results) {
            // Get current assignments
            $current = [
                'group' => $employee->groups->pluck('id')->toArray(),
                'role' => $employee->roles->pluck('id')->toArray(),
                'permission' => $employee->permissions->pluck('id')->toArray(),
            ];

            // Remove assignments not in new list
            foreach ($current as $type => $currentIds) {
                $newIds = $assignments[$type] ?? [];
                $toRemove = array_diff($currentIds, $newIds);

                if (!empty($toRemove)) {
                    $modelClass = $this->getModelClass($type);

                    foreach ($toRemove as $id) {
                        try {
                            $assignable = $modelClass::find($id);
                            if ($assignable) {
                                $employee->unassign($assignable, "Synced: {$reason}");
                                $results['removed'][] = [
                                    'type' => $type,
                                    'id' => $id,
                                ];
                            }
                        } catch (\Exception $e) {
                            // Log error but continue
                        }
                    }
                }
            }

            // Add new assignments
            foreach ($assignments as $type => $newIds) {
                $currentIds = $current[$type] ?? [];
                $toAdd = array_diff($newIds, $currentIds);

                if (!empty($toAdd)) {
                    $modelClass = $this->getModelClass($type);

                    foreach ($toAdd as $id) {
                        try {
                            $assignable = $modelClass::find($id);
                            if ($assignable) {
                                $employee->assign($assignable, "Synced: {$reason}", null, $assignedBy);
                                $results['added'][] = [
                                    'type' => $type,
                                    'id' => $id,
                                ];
                            }
                        } catch (\Exception $e) {
                            // Log error but continue
                        }
                    }
                }

                // Track unchanged
                $unchanged = array_intersect($currentIds, $newIds);
                foreach ($unchanged as $id) {
                    $results['unchanged'][] = [
                        'type' => $type,
                        'id' => $id,
                    ];
                }
            }
        });

        return $results;
    }

    /**
     * Get model class by type
     */
    protected function getModelClass(string $type): string
    {
        return match ($type) {
            'group' => Group::class,
            'role' => Role::class,
            'permission' => Permission::class,
            default => throw new \InvalidArgumentException("Invalid assignment type: {$type}"),
        };
    }

    /**
     * Get assignments expiring soon
     */
    public function getExpiringAssignments(int $days = 7)
    {
        return Assignment::with(['employee', 'assignable', 'assigner'])
            ->active()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Bulk assign to multiple employees
     */
    public function bulkAssign(array $employeeIds, array $assignments, string $reason = null, $assignedBy = null): array
    {
        $results = [];

        foreach ($employeeIds as $employeeId) {
            try {
                $employee = Employee::findOrFail($employeeId);
                $result = $this->assignToEmployee($employee, $assignments, $reason, $assignedBy);

                $results[$employeeId] = [
                    'success' => true,
                    'assignments' => $result,
                ];
            } catch (\Exception $e) {
                $results[$employeeId] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Get assignment statistics
     */
    public function getStatistics(): array
    {
        $total = Assignment::count();
        $active = Assignment::active()->count();
        $expired = Assignment::expired()->count();
        $inactive = Assignment::where('is_active', false)->count();

        $byType = Assignment::select('assignable_type', DB::raw('count(*) as count'))
            ->groupBy('assignable_type')
            ->pluck('count', 'assignable_type')
            ->toArray();

        return [
            'total' => $total,
            'active' => $active,
            'expired' => $expired,
            'inactive' => $inactive,
            'by_type' => $byType,
            'active_percentage' => $total > 0 ? round(($active / $total) * 100, 2) : 0,
        ];
    }
}