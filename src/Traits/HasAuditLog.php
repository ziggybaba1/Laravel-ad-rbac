<?php

namespace LaravelAdRbac\Traits;

use LaravelAdRbac\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

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

        static::restored(function ($model) {
            $model->logAuditEvent('restored', $model->getAttributes(), null);
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
            'action' => $this->getAuditAction($event),
            'model_type' => get_class($this),
            'properties' => $this->getAuditProperties($event),
        ]);
    }

    /**
     * Generate audit description
     */
    protected function getAuditDescription(string $event, ?array $oldValues, ?array $newValues): string
    {
        $modelName = class_basename($this);
        $key = $this->getKey();

        return match ($event) {
            'created' => "{$modelName} [ID: {$key}] was created",
            'updated' => "{$modelName} [ID: {$key}] was updated",
            'deleted' => "{$modelName} [ID: {$key}] was deleted",
            'restored' => "{$modelName} [ID: {$key}] was restored",
            default => "{$modelName} [ID: {$key}] {$event}",
        };
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
            default => strtoupper($event),
        };
    }

    /**
     * Get additional audit properties
     */
    protected function getAuditProperties(string $event): array
    {
        return [
            'model_class' => get_class($this),
            'model_id' => $this->getKey(),
            'event' => $event,
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the audit logs for this model
     */
    public function auditLogs()
    {
        return $this->morphMany(AuditLog::class, 'auditable');
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
            'description' => $description ?? "Custom event '{$event}' performed on " . class_basename($this),
            'action' => strtoupper($event),
            'model_type' => get_class($this),
            'properties' => array_merge(
                $this->getAuditProperties($event),
                ['custom_event' => true, 'custom_data' => $data]
            ),
        ]);
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