<?php

namespace LaravelAdRbac\Repositories;

use LaravelAdRbac\Models\AuditLog;

class AuditLogRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new AuditLog());
    }

    /**
     * Get logs with filters and pagination
     */
    public function getFilteredLogs(
        array $filters = [],
        array $with = ['auditable', 'causer'],
        int $perPage = 25,
        array $sort = ['created_at', 'desc']
    ) {
        $query = $this->model->query();

        // Apply filters
        foreach ($filters as $key => $value) {
            if ($value !== null && $value !== '') {
                $query->where($key, $value);
            }
        }

        // Load relationships
        if (!empty($with)) {
            $query->with($with);
        }

        // Apply sorting
        $query->orderBy($sort[0], $sort[1]);

        return $query->paginate($perPage);
    }
}