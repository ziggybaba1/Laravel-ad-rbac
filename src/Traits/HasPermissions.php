<?php

namespace LaravelAdRbac\Traits;

trait HasPermissions
{
    /**
     * Boot the trait
     */
    public static function bootHasPermissions()
    {
        static::created(function ($model) {
            \Log::info('Model created event fired', [
                'model' => get_class($model),
                'model_id' => $model->id
            ]);

            app(\LaravelAdRbac\Services\PermissionScanner::class)
                ->syncModelPermissions($model);
        });
    }

    /**
     * Get permission actions for this model
     * Can be overridden in the actual model
     */
    public function getPermissionActions(): array
    {
        return array_merge(
            ['create', 'read', 'update', 'delete'],
            $this->getSpecialActionsFromColumns()
        );
    }

    /**
     * Auto-detect special actions from column names
     */
    protected function getSpecialActionsFromColumns(): array
    {
        $specialActions = [];
        $configActions = config('ad-rbac.permissions.special_actions', []);

        foreach ($this->getFillable() as $column) {
            foreach ($configActions as $action) {
                if (str_contains($column, $action)) {
                    $specialActions[] = $action;
                    break;
                }
            }
        }

        return array_unique($specialActions);
    }

    /**
     * Get permission slug for an action
     */
    public function getPermissionSlug(string $action): string
    {
        $modelName = class_basename($this);
        return strtolower($modelName) . '.' . $action;
    }
}