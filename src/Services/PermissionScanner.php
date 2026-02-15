<?php

namespace LaravelAdRbac\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ReflectionClass;
use LaravelAdRbac\Models\Permission;

class PermissionScanner
{
    protected $specialActions;

    public function __construct()
    {
        $this->specialActions = config('ad-rbac.permissions.special_actions', []);
    }

    /**
     * Scan all models and sync permissions
     */
    public function scanAndSync(): void
    {
        $models = $this->discoverPermissionableModels();

        foreach ($models as $modelClass) {
            $this->syncModelPermissions($modelClass);
        }

        // Clean up orphaned permissions
        $this->cleanupOrphanedPermissions($models);
    }

    /**
     * Discover models that are permissionable
     */
    public function discoverPermissionableModels(): array
    {
        $modelsPath = app_path('Models');
        $modelFiles = glob($modelsPath . '/*.php');
        $permissionableModels = [];

        foreach ($modelFiles as $file) {
            $fullClassName = $this->getFullClassNameFromFile($file);

            if (!$fullClassName) {
                \Log::warning('Could not extract class name from file', ['file' => $file]);
                continue;
            }

            \Log::info('Checking class', [
                'file' => $file,
                'class' => $fullClassName,
                'exists' => class_exists($fullClassName)
            ]);

            if (!class_exists($fullClassName)) {
                // Try to include the file
                try {
                    include_once $file;
                    if (!class_exists($fullClassName)) {
                        \Log::warning('Class still not found after include', ['class' => $fullClassName]);
                        continue;
                    }
                } catch (\Throwable $e) {
                    \Log::error('Error including file', [
                        'file' => $file,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }

            try {
                $reflection = new ReflectionClass($fullClassName);

                // First, check if it's an Eloquent model
                if (!$reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                    \Log::info('Skipping: Not an Eloquent model', ['class' => $fullClassName]);
                    continue;
                }

                // Then check for trait
                if ($this->isPermissionableModel($reflection)) {
                    $permissionableModels[] = $fullClassName;
                    \Log::info('✓ Added permissionable model', ['model' => $fullClassName]);
                }
            } catch (\ReflectionException $e) {
                \Log::error('Reflection failed', [
                    'class' => $fullClassName,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $permissionableModels;
    }

    /**
     * Extract full class name from file
     */
    protected function getFullClassNameFromFile(string $file): ?string
    {
        $content = file_get_contents($file);

        // Extract namespace
        $namespace = '';
        if (preg_match('/namespace\s+([^;]+)/', $content, $matches)) {
            $namespace = $matches[1];
        }

        // Extract class name
        $className = '';
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $className = $matches[1];
        }

        if (!$className) {
            return null;
        }

        return $namespace ? $namespace . '\\' . $className : $className;
    }

    /**
     * Check if a model is permissionable
     */
    protected function isPermissionableModel(ReflectionClass $reflection): bool
    {
        // Skip excluded models
        $excluded = config('ad-rbac.permissions.excluded_models', []);
        if (in_array($reflection->getName(), $excluded)) {
            return false;
        }

        // Check for Permissionable trait
        $traits = $reflection->getTraitNames();
        $permissionableTrait = 'LaravelAdRbac\\Traits\\HasPermissions';

        return in_array($permissionableTrait, $traits);
    }

    /**
     * Sync permissions for a specific model
     */
    public function syncModelPermissions($modelClass): void
    {
        if (is_object($modelClass)) {
            $modelClass = get_class($modelClass);
        }

        $model = app($modelClass);
        $actions = [];

        // Get actions from model's getPermissionActions method
        if (method_exists($model, 'getPermissionActions')) {
            $actions = $model->getPermissionActions();
        } else {
            // Default actions
            $actions = array_merge(
                ['create', 'read', 'update', 'delete'],
                $this->detectSpecialActions($model)
            );
        }

        // Create/update permissions
        foreach ($actions as $action) {
            Permission::updateOrCreate(
                [
                    'module' => $modelClass,
                    'action' => $action,
                ],
                [
                    'name' => $this->generatePermissionName($modelClass, $action),
                    'slug' => $this->generatePermissionSlug($modelClass, $action),
                    'action' => $action,
                    'module' => $modelClass,
                ]
            );
        }

        // Remove actions that no longer exist
        $existingActions = Permission::where('module', $modelClass)->pluck('action')->toArray();
        $actionsToRemove = array_diff($existingActions, $actions);

        if (!empty($actionsToRemove)) {
            Permission::where('module', $modelClass)
                ->whereIn('action', $actionsToRemove)
                ->delete();
        }
    }

    /**
     * Detect special actions from model columns
     */
    protected function detectSpecialActions($model): array
    {
        $specialActions = [];

        try {
            $table = $model->getTable();
            $columns = \Schema::getColumnListing($table);

            foreach ($columns as $column) {
                foreach ($this->specialActions as $action) {
                    if (Str::contains($column, $action)) {
                        $specialActions[] = $action;
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            // Table might not exist yet
        }

        return array_unique($specialActions);
    }

    /**
     * Generate permission name
     */
    protected function generatePermissionName(string $modelClass, string $action): string
    {
        $modelName = class_basename($modelClass);

        // Convert PascalCase/camelCase to words and title case
        $formattedModelName = Str::title(preg_replace('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $modelName));

        return ucfirst($action) . ' ' . $formattedModelName;
    }

    /**
     * Generate permission slug
     */
    protected function generatePermissionSlug(string $modelClass, string $action): string
    {
        $modelName = class_basename($modelClass);
        return Str::snake($modelName) . '.' . $action;
    }

    /**
     * Clean up permissions for models that no longer exist
     */
    protected function cleanupOrphanedPermissions(array $currentModels): void
    {
        $orphaned = Permission::whereNotIn('module', $currentModels)
            ->where('module', 'LIKE', 'App\\Models\\%')
            ->get();

        foreach ($orphaned as $permission) {
            $permission->delete();
        }
    }
}