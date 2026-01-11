<?php

namespace LaravelAdRbac\Services;

use LaravelAdRbac\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AuditLogService
{
    /**
     * Get all audit logs with filtering and pagination
     */
    public function getAllLogs(
        array $filters = [],
        array $excludeColumns = [],
        int $perPage = 25,
        array $sort = ['created_at', 'desc']
    ): LengthAwarePaginator {
        $query = AuditLog::query();

        // Apply filters
        $this->applyFilters($query, $filters);

        // Exclude columns
        if (!empty($excludeColumns)) {
            $allColumns = $this->getAuditLogColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        // Load relationships
        $query->with(['auditable', 'causer']);

        // Apply sorting
        $query->orderBy($sort[0], $sort[1]);

        return $query->paginate($perPage);
    }

    /**
     * Get audit logs for a specific model
     */
    public function getModelLogs(
        string $modelType,
        ?int $modelId = null,
        array $filters = [],
        int $perPage = 25
    ): LengthAwarePaginator {
        $filters['model_type'] = $modelType;

        if ($modelId !== null) {
            $filters['auditable_id'] = $modelId;
        }

        return $this->getAllLogs($filters, [], $perPage);
    }

    /**
     * Get audit logs for a specific causer (user)
     */
    public function getUserLogs(
        string $causerType,
        ?int $causerId = null,
        array $filters = [],
        int $perPage = 25
    ): LengthAwarePaginator {
        $filters['causer_type'] = $causerType;

        if ($causerId !== null) {
            $filters['causer_id'] = $causerId;
        }

        return $this->getAllLogs($filters, [], $perPage);
    }

    /**
     * Get audit logs by event type
     */
    public function getLogsByEvent(
        string $event,
        array $filters = [],
        int $perPage = 25
    ): LengthAwarePaginator {
        $filters['event'] = $event;
        return $this->getAllLogs($filters, [], $perPage);
    }

    /**
     * Get audit logs by date range
     */
    public function getLogsByDateRange(
        $startDate,
        $endDate = null,
        array $filters = [],
        int $perPage = 25
    ): LengthAwarePaginator {
        $query = AuditLog::query();

        $this->applyFilters($query, $filters);
        $query->dateRange($startDate, $endDate);

        return $query->with(['auditable', 'causer'])->paginate($perPage);
    }

    /**
     * Search audit logs by text in description or values
     */
    public function searchLogs(
        string $searchTerm,
        array $filters = [],
        int $perPage = 25
    ): LengthAwarePaginator {
        $query = AuditLog::query();

        $this->applyFilters($query, $filters);

        $query->where(function ($q) use ($searchTerm) {
            $q->where('description', 'LIKE', "%{$searchTerm}%")
                ->orWhere('old_values', 'LIKE', "%{$searchTerm}%")
                ->orWhere('new_values', 'LIKE', "%{$searchTerm}%")
                ->orWhere('model_type', 'LIKE', "%{$searchTerm}%");
        });

        return $query->with(['auditable', 'causer'])->paginate($perPage);
    }

    /**
     * Get audit log statistics
     */
    public function getStatistics(array $filters = []): array
    {
        $query = AuditLog::query();
        $this->applyFilters($query, $filters);

        return [
            'total_logs' => $query->count(),
            'by_event' => $query->clone()
                ->select('event', DB::raw('COUNT(*) as count'))
                ->groupBy('event')
                ->pluck('count', 'event')
                ->toArray(),
            'by_model' => $query->clone()
                ->select('model_type', DB::raw('COUNT(*) as count'))
                ->groupBy('model_type')
                ->pluck('count', 'model_type')
                ->toArray(),
            'by_action' => $query->clone()
                ->select('action', DB::raw('COUNT(*) as count'))
                ->groupBy('action')
                ->pluck('count', 'action')
                ->toArray(),
            'by_causer_type' => $query->clone()
                ->select('causer_type', DB::raw('COUNT(*) as count'))
                ->whereNotNull('causer_type')
                ->groupBy('causer_type')
                ->pluck('count', 'causer_type')
                ->toArray(),
            'recent_activity' => $query->clone()
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy(DB::raw('DATE(created_at)'))
                ->orderBy('date', 'desc')
                ->get()
                ->toArray(),
        ];
    }

    /**
     * Get field change history for a specific model field
     */
    public function getFieldHistory(
        string $modelType,
        int $modelId,
        string $fieldName,
        int $limit = 10
    ): Collection {
        return AuditLog::where('model_type', $modelType)
            ->where('auditable_id', $modelId)
            ->whereJsonContains('changed_fields', $fieldName)
            ->with('causer')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($log) use ($fieldName) {
                return [
                    'id' => $log->id,
                    'event' => $log->event,
                    'old_value' => $log->old_values[$fieldName] ?? null,
                    'new_value' => $log->new_values[$fieldName] ?? null,
                    'changed_at' => $log->created_at,
                    'changed_by' => $log->causer,
                    'description' => $log->description,
                ];
            });
    }

    /**
     * Export audit logs to array (for CSV/Excel export)
     */
    public function exportLogs(array $filters = [], int $limit = 1000): array
    {
        $query = AuditLog::query();
        $this->applyFilters($query, $filters);

        $logs = $query->with(['auditable', 'causer'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $logs->map(function ($log) {
            return [
                'id' => $log->id,
                'timestamp' => $log->created_at->toDateTimeString(),
                'event' => $log->event,
                'action' => $log->action,
                'model_type' => $log->model_type,
                'model_id' => $log->auditable_id,
                'causer_type' => $log->causer_type,
                'causer_id' => $log->causer_id,
                'causer_name' => $log->causer ? $this->getCauserName($log->causer) : null,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'url' => $log->url,
                'description' => $log->description,
                'changed_fields' => implode(', ', $log->changed_fields ?? []),
                'old_values' => json_encode($log->old_values, JSON_PRETTY_PRINT),
                'new_values' => json_encode($log->new_values, JSON_PRETTY_PRINT),
            ];
        })->toArray();
    }

    /**
     * Clean up old audit logs
     */
    public function cleanupOldLogs(int $daysToKeep = 90): int
    {
        $cutoffDate = now()->subDays($daysToKeep);

        return AuditLog::where('created_at', '<', $cutoffDate)->delete();
    }

    /**
     * Log a manual audit event (for custom actions)
     */
    public function logManualEvent(
        string $event,
        string $description,
        $auditable = null,
        $causer = null,
        array $data = []
    ): AuditLog {
        $logData = [
            'event' => $event,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->fullUrl(),
            'action' => strtoupper($event),
            'properties' => array_merge($data, ['manual_log' => true]),
        ];

        if ($auditable) {
            $logData['auditable_type'] = get_class($auditable);
            $logData['auditable_id'] = $auditable->getKey();
            $logData['model_type'] = get_class($auditable);
        }

        if ($causer) {
            $logData['causer_type'] = get_class($causer);
            $logData['causer_id'] = $causer->getKey();
        }

        if (!empty($data)) {
            $logData['new_values'] = $data;
            $logData['changed_fields'] = array_keys($data);
        }

        return AuditLog::create($logData);
    }

    /**
     * Apply filters to query
     */
    protected function applyFilters($query, array $filters): void
    {
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            switch ($key) {
                case 'event':
                case 'action':
                case 'model_type':
                case 'causer_type':
                    $query->where($key, $value);
                    break;

                case 'causer_id':
                case 'auditable_id':
                    $query->where($key, $value);
                    break;

                case 'date_from':
                    $query->where('created_at', '>=', $value);
                    break;

                case 'date_to':
                    $query->where('created_at', '<=', $value);
                    break;

                case 'search':
                    $query->where(function ($q) use ($value) {
                        $q->where('description', 'LIKE', "%{$value}%")
                            ->orWhere('model_type', 'LIKE', "%{$value}%");
                    });
                    break;

                case 'has_changes':
                    if ($value) {
                        $query->whereNotNull('changed_fields')
                            ->whereJsonLength('changed_fields', '>', 0);
                    }
                    break;
            }
        }
    }

    /**
     * Get audit log table columns
     */
    protected function getAuditLogColumns(): array
    {
        return [
            'id',
            'event',
            'auditable_type',
            'auditable_id',
            'causer_type',
            'causer_id',
            'ip_address',
            'user_agent',
            'url',
            'old_values',
            'new_values',
            'changed_fields',
            'description',
            'action',
            'model_type',
            'properties',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * Get causer name for display
     */
    protected function getCauserName($causer): string
    {
        if (method_exists($causer, 'getName')) {
            return $causer->getName();
        }

        if (method_exists($causer, 'getFullName')) {
            return $causer->getFullName();
        }

        if (isset($causer->name)) {
            return $causer->name;
        }

        if (isset($causer->email)) {
            return $causer->email;
        }

        return 'Unknown';
    }
}