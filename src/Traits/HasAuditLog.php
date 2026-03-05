<?php

namespace LaravelAdRbac\Traits;

use LaravelAdRbac\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Database\Eloquent\SoftDeletes;

trait HasAuditLog
{
    /**
     * Boot the audit logging trait
     */
    public static function bootHasAuditLog(): void
    {
        static::created(function ($model) {
            $model->logAuditEvent('created', $model->getAttributes(), null);
        });

        static::updated(function ($model) {
            $original = $model->getOriginal();
            $changes = $model->getChanges();

            // Remove timestamps from changes
            unset($changes['created_at'], $changes['updated_at']);

            if (!empty($changes)) {
                $oldValues = array_intersect_key($original, $changes);
                $model->logAuditEvent('updated', $changes, $oldValues);
            }
        });

        static::deleted(function ($model) {
            $model->logAuditEvent('deleted', null, $model->getOriginal());
        });
    }

    /**
     * Log an audit event
     */
    public function logAuditEvent(string $event, ?array $newValues = null, ?array $oldValues = null): AuditLog
    {
        $changedFields = null;

        if ($oldValues && $newValues) {
            $changedFields = array_keys(array_diff_assoc($newValues, $oldValues));
        }

        $description = $this->getAuditDescription($event, $oldValues, $newValues);
        $action = $this->getAuditAction($event);

        return AuditLog::create([
            'event' => $event,
            'auditable_type' => get_class($this),
            'auditable_id' => $this->getKey(),
            'causer_type' => Auth::check() ? get_class(Auth::user()) : null,
            'causer_id' => Auth::id(),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'url' => Request::fullUrl(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'changed_fields' => $changedFields,
            'description' => $description,
            'action' => $action,
            'model_type' => get_class($this),
            'properties' => $this->getAuditProperties($event, $action),
        ]);
    }

    /**
     * Generate audit description
     */
    protected function getAuditDescription(string $event, ?array $oldValues, ?array $newValues): string
    {
        $modelName = class_basename($this);
        $key = $this->getKey();
        $identifier = $this->getModelIdentifier();

        // Check if this was from updateOrCreate (you'll need to set this flag)
        $isUpdateOrCreate = $this->isFromUpdateOrCreate ?? false;

        return match ($event) {
            'created' => $isUpdateOrCreate
            ? $this->getUpdateOrCreateCreatedDescription($modelName, $identifier, $newValues)
            : $this->getCreatedDescription($modelName, $identifier, $newValues),

            'updated' => $isUpdateOrCreate
            ? $this->getUpdateOrCreateUpdatedDescription($modelName, $identifier, $oldValues, $newValues)
            : $this->getUpdatedDescription($modelName, $identifier, $oldValues, $newValues),

            'deleted' => $this->getDeletedDescription($modelName, $identifier, $oldValues),
            'restored' => "{$modelName} '{$identifier}' [ID: {$key}] was restored from trash",
            'forceDeleted' => "{$modelName} '{$identifier}' [ID: {$key}] was permanently deleted",

            // Additional events
            'exported' => "{$modelName} '{$identifier}' [ID: {$key}] was exported",
            'imported' => "{$modelName} '{$identifier}' [ID: {$key}] was imported",
            'downloaded' => "{$modelName} '{$identifier}' [ID: {$key}] was downloaded",
            'uploaded' => "File was uploaded to {$modelName} '{$identifier}' [ID: {$key}]",
            'viewed' => "{$modelName} '{$identifier}' [ID: {$key}] was viewed",
            'duplicated' => "{$modelName} '{$identifier}' [ID: {$key}] was duplicated",
            'archived' => "{$modelName} '{$identifier}' [ID: {$key}] was archived",
            'unarchived' => "{$modelName} '{$identifier}' [ID: {$key}] was unarchived",
            'approved' => "{$modelName} '{$identifier}' [ID: {$key}] was approved",
            'rejected' => "{$modelName} '{$identifier}' [ID: {$key}] was rejected",
            'published' => "{$modelName} '{$identifier}' [ID: {$key}] was published",
            'unpublished' => "{$modelName} '{$identifier}' [ID: {$key}] was unpublished",
            'assigned' => $this->getAssignedDescription($modelName, $identifier, $newValues),
            'unassigned' => $this->getUnassignedDescription($modelName, $identifier, $oldValues),
            'statusChanged' => $this->getStatusChangedDescription($identifier, $oldValues, $newValues),

            // Auth events
            'login' => "User '{$identifier}' logged in",
            'logout' => "User '{$identifier}' logged out",
            'passwordChanged' => "User '{$identifier}' changed their password",

            // Permission/Role events
            'permissionUpdated' => "Permissions were updated for {$modelName} '{$identifier}'",
            'roleUpdated' => "Role was updated for {$modelName} '{$identifier}'",
            'settingsChanged' => $this->getSettingsChangedDescription($identifier, $oldValues, $newValues),

            // Relationship events
            'attached' => $this->getRelationshipDescription('attached', $identifier, $newValues),
            'detached' => $this->getRelationshipDescription('detached', $identifier, $oldValues),
            'synced' => $this->getRelationshipDescription('synced', $identifier, $newValues),

            default => "{$modelName} '{$identifier}' [ID: {$key}] was {$event}",
        };
    }

    /**
     * Get model identifier (name, title, etc.)
     */
    protected function getModelIdentifier(): string
    {
        if (isset($this->full_name)) {
            return $this->full_name;
        }
        if (isset($this->title)) {
            return $this->title;
        }
        if (isset($this->email)) {
            return $this->email;
        }
        if (isset($this->description)) {
            return $this->description;
        }
        if (isset($this->name)) {
            return $this->name;
        }
        if (isset($this->label)) {
            return $this->label;
        }
        return "ID: {$this->getKey()}";
    }

    /**
     * Get created description
     */
    protected function getCreatedDescription(string $modelName, string $identifier, ?array $newValues): string
    {
        $fields = $this->getNotableFields($newValues);
        return empty($fields)
            ? "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was created"
            : "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was created with: " . implode(', ', $fields);
    }

    /**
     * Get updated description
     */
    protected function getUpdatedDescription(string $modelName, string $identifier, ?array $oldValues, ?array $newValues): string
    {
        $changes = $this->getFormattedChanges($oldValues, $newValues);

        if (empty($changes)) {
            return "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was updated (no significant changes)";
        }

        return "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was updated: " . implode('; ', $changes);
    }

    /**
     * Get deleted description
     */
    protected function getDeletedDescription(string $modelName, string $identifier, ?array $oldValues): string
    {
        $fields = $this->getNotableFields($oldValues);
        return empty($fields)
            ? "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was deleted"
            : "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was deleted (had: " . implode(', ', $fields) . ")";
    }

    /**
     * Get updateOrCreate created description
     */
    protected function getUpdateOrCreateCreatedDescription(string $modelName, string $identifier, ?array $newValues): string
    {
        $searchCriteria = $this->formatSearchCriteria($newValues['_search_criteria'] ?? []);
        return "{$modelName} '{$identifier}' was created via updateOrCreate (matched: {$searchCriteria})";
    }

    /**
     * Get updateOrCreate updated description
     */
    protected function getUpdateOrCreateUpdatedDescription(string $modelName, string $identifier, ?array $oldValues, ?array $newValues): string
    {
        $searchCriteria = $this->formatSearchCriteria($newValues['_search_criteria'] ?? []);
        $changes = $this->getFormattedChanges($oldValues, $newValues);

        if (empty($changes)) {
            return "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was updated via updateOrCreate (matched: {$searchCriteria})";
        }

        return "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was updated via updateOrCreate (matched: {$searchCriteria}): " . implode('; ', $changes);
    }

    /**
     * Get assigned description
     */
    protected function getAssignedDescription(string $modelName, string $identifier, ?array $newValues): string
    {
        $assignee = $newValues['assigned_to'] ?? $newValues['user_id'] ?? $newValues['assignee'] ?? 'someone';
        $assigneeName = $this->getAssigneeName($assignee);
        return "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was assigned to {$assigneeName}";
    }

    /**
     * Get unassigned description
     */
    protected function getUnassignedDescription(string $modelName, string $identifier, ?array $oldValues): string
    {
        $assignee = $oldValues['assigned_to'] ?? $oldValues['user_id'] ?? $oldValues['assignee'] ?? 'someone';
        $assigneeName = $this->getAssigneeName($assignee);
        return "{\Illuminate\Support\Str::headline($modelName)} '{$identifier}' was unassigned from {$assigneeName}";
    }

    /**
     * Get status changed description
     */
    protected function getStatusChangedDescription(string $identifier, ?array $oldValues, ?array $newValues): string
    {
        $oldStatus = $oldValues['status'] ?? 'unknown';
        $newStatus = $newValues['status'] ?? 'unknown';
        return "Status changed from '{$oldStatus}' to '{$newStatus}' for '{$identifier}'";
    }

    /**
     * Get settings changed description
     */
    protected function getSettingsChangedDescription(string $identifier, ?array $oldValues, ?array $newValues): string
    {
        $changes = [];
        foreach ($newValues ?? [] as $key => $value) {
            $oldValue = $oldValues[$key] ?? 'not set';
            if ($oldValue != $value) {
                $changes[] = "{$key}: '{$this->formatValue($oldValue)}' → '{$this->formatValue($value)}'";
            }
        }

        return empty($changes)
            ? "Settings updated for '{$identifier}'"
            : "Settings changed for '{$identifier}': " . implode(', ', $changes);
    }

    /**
     * Get relationship description
     */
    protected function getRelationshipDescription(string $action, string $identifier, ?array $values): string
    {
        $relation = $values['relation'] ?? 'items';
        $related = $values['related'] ?? [];
        $count = is_array($related) ? count($related) : 0;

        return match ($action) {
            'attached' => "{$count} {$relation} attached to {$identifier}",
            'detached' => "{$count} {$relation} detached from {$identifier}",
            'synced' => "{$relation} synced for {$identifier} ({$count} items)",
            default => "{$action} on {$relation} for {$identifier}",
        };
    }

    /**
     * Format search criteria for description
     */
    protected function formatSearchCriteria(array $criteria): string
    {
        if (empty($criteria)) {
            return 'no criteria';
        }

        $formatted = [];
        foreach ($criteria as $field => $value) {
            $formatted[] = "{$field}: {$this->formatValue($value)}";
        }

        return implode(', ', $formatted);
    }

    /**
     * Get assignee name
     */
    protected function getAssigneeName($assignee): string
    {
        if (is_numeric($assignee) && class_exists('App\Models\Employee')) {
            $user = \App\Models\Employee::find($assignee);
            return $user ? $user->full_name ?? $user->email ?? "Employee #{$assignee}" : "Employee #{$assignee}";
        }
        return (string) $assignee;
    }

    /**
     * Get formatted changes between old and new values
     */
    protected function getFormattedChanges(?array $oldValues, ?array $newValues): array
    {
        $changes = [];
        $ignoredFields = ['updated_at', 'created_at', 'deleted_at', 'remember_token', '_search_criteria'];

        foreach ($newValues ?? [] as $key => $newValue) {
            if (in_array($key, $ignoredFields)) {
                continue;
            }

            $oldValue = $oldValues[$key] ?? null;

            if ($this->valuesAreDifferent($oldValue, $newValue)) {
                $fieldName = str_replace('_', ' ', $key);
                $changes[] = "{$fieldName}: {$this->formatValue($oldValue)} → {$this->formatValue($newValue)}";
            }
        }

        return $changes;
    }

    /**
     * Check if values are different
     */
    protected function valuesAreDifferent($oldValue, $newValue): bool
    {
        if (is_null($oldValue) && is_null($newValue)) {
            return false;
        }

        if (is_null($oldValue) || is_null($newValue)) {
            return true;
        }

        if (is_array($oldValue) || is_array($newValue)) {
            return json_encode($oldValue) !== json_encode($newValue);
        }

        return $oldValue != $newValue;
    }

    /**
     * Get notable fields (important fields like name, email, etc.)
     */
    protected function getNotableFields(?array $values): array
    {
        if (empty($values)) {
            return [];
        }

        $notable = [];
        $importantFields = ['full_name', 'title', 'email', 'username', 'code', 'status', 'amount', 'price', 'role'];

        foreach ($importantFields as $field) {
            if (isset($values[$field]) && !empty($values[$field])) {
                $notable[] = "{$field}: {$this->formatValue($values[$field])}";
            }
        }

        return $notable;
    }

    /**
     * Format value for display
     */
    protected function formatValue($value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_string($value) && strlen($value) > 50) {
            return substr($value, 0, 50) . '...';
        }

        return (string) $value;
    }

    /**
     * Get audit action from event
     */
    protected function getAuditAction(string $event): string
    {
        return match ($event) {
            'created' => 'CREATE',
            'updated' => 'UPDATE',
            'deleted' => 'DELETE',
            'restored' => 'RESTORE',
            'forceDeleted' => 'FORCE_DELETE',
            'exported' => 'EXPORT',
            'imported' => 'IMPORT',
            'downloaded' => 'DOWNLOAD',
            'uploaded' => 'UPLOAD',
            'viewed' => 'VIEW',
            'duplicated' => 'DUPLICATE',
            'archived' => 'ARCHIVE',
            'unarchived' => 'UNARCHIVE',
            'approved' => 'APPROVE',
            'rejected' => 'REJECT',
            'published' => 'PUBLISH',
            'unpublished' => 'UNPUBLISH',
            'assigned' => 'ASSIGN',
            'unassigned' => 'UNASSIGN',
            'statusChanged' => 'STATUS_CHANGE',
            'login' => 'LOGIN',
            'logout' => 'LOGOUT',
            'passwordChanged' => 'PASSWORD_CHANGE',
            'permissionUpdated' => 'PERMISSION_UPDATE',
            'roleUpdated' => 'ROLE_UPDATE',
            'settingsChanged' => 'SETTINGS_CHANGE',
            'attached' => 'ATTACH',
            'detached' => 'DETACH',
            'synced' => 'SYNC',
            default => strtoupper($event),
        };
    }

    /**
     * Get additional audit properties
     */
    protected function getAuditProperties(string $event, string $action): array
    {
        return [
            'model_class' => get_class($this),
            'model_id' => $this->getKey(),
            'event' => $event,
            'action' => $action,
            'timestamp' => now()->toISOString(),
            'user_id' => Auth::id(),
            'user_name' => Auth::check() ? Auth::user()->name ?? Auth::user()->email : null,
            'ip' => Request::ip(),
        ];
    }

    /**
     * Log a custom audit event
     */
    public function logCustomEvent(string $event, array $data = [], ?string $description = null): AuditLog
    {
        return AuditLog::create([
            'event' => $event,
            'auditable_type' => get_class($this),
            'auditable_id' => $this->getKey(),
            'causer_type' => Auth::check() ? get_class(Auth::user()) : null,
            'causer_id' => Auth::id(),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'url' => Request::fullUrl(),
            'old_values' => null,
            'new_values' => $data,
            'changed_fields' => array_keys($data),
            'description' => $description ?? $this->getAuditDescription($event, null, $data),
            'action' => $this->getAuditAction($event),
            'model_type' => get_class($this),
            'properties' => array_merge(
                $this->getAuditProperties($event, $this->getAuditAction($event)),
                ['custom_event' => true, 'custom_data' => $data]
            ),
        ]);
    }

    /**
     * Log updateOrCreate event
     */
    public function logUpdateOrCreateEvent(array $searchCriteria, array $values): AuditLog
    {
        $this->isFromUpdateOrCreate = true;

        $event = $this->wasRecentlyCreated ? 'created' : 'updated';
        $newValues = array_merge($values, ['_search_criteria' => $searchCriteria]);

        return $this->logAuditEvent($event, $newValues, $this->wasRecentlyCreated ? null : $this->getOriginal());
    }

    /**
     * Get the audit logs for this model
     */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * Get the last audit log for this model
     */
    public function getLastAuditLog(): ?AuditLog
    {
        return $this->auditLogs()->latest()->first();
    }

    /**
     * Get audit logs for a specific event
     */
    public function getAuditLogsByEvent(string $event)
    {
        return $this->auditLogs()->event($event)->get();
    }

    /**
     * Get audit logs within a date range
     */
    public function getAuditLogsByDateRange($startDate, $endDate = null)
    {
        return $this->auditLogs()->dateRange($startDate, $endDate)->get();
    }
}