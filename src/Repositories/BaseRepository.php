<?php

namespace LaravelAdRbac\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class BaseRepository
{
    protected $model;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Get all records with optional column exclusion and pagination
     */
    public function all(array $excludeColumns = [], int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = $this->model->query();

        // Exclude columns if specified
        if (!empty($excludeColumns)) {
            $allColumns = $this->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        // Paginate or get all
        if ($perPage) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    /**
     * Find by ID with optional column exclusion
     */
    public function find(int $id, array $excludeColumns = []): ?Model
    {
        $query = $this->model->query();

        if (!empty($excludeColumns)) {
            $allColumns = $this->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        return $query->find($id);
    }

    /**
     * Create a new record
     */
    public function create(array $data): Model
    {
        return $this->model->create($data);
    }

    /**
     * Update a record
     */
    public function update(int $id, array $data): bool
    {
        $model = $this->find($id);

        if (!$model) {
            return false;
        }

        return $model->update($data);
    }

    /**
     * Delete a record
     */
    public function delete(int $id): bool
    {
        $model = $this->find($id);

        if (!$model) {
            return false;
        }

        return $model->delete();
    }

    /**
     * Get table columns
     */
    public function getTableColumns(): array
    {
        return $this->model->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($this->model->getTable());
    }

    /**
     * Search with filters
     */
    public function search(array $filters = [], array $excludeColumns = [], int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = $this->model->query();

        // Apply filters
        foreach ($filters as $column => $value) {
            if (is_array($value)) {
                $query->whereIn($column, $value);
            } else {
                $query->where($column, $value);
            }
        }

        // Exclude columns
        if (!empty($excludeColumns)) {
            $allColumns = $this->getTableColumns();
            $selectColumns = array_diff($allColumns, $excludeColumns);
            $query->select($selectColumns);
        }

        // Paginate or get all
        if ($perPage) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }
}