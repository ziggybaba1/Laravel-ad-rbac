<?php

namespace LaravelAdRbac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
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
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'changed_fields' => 'array',
        'properties' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the model that was audited
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user/entity that performed the action
     */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope for specific event
     */
    public function scopeEvent($query, string $event)
    {
        return $query->where('event', $event);
    }

    /**
     * Scope for specific model
     */
    public function scopeModel($query, string $modelType)
    {
        return $query->where('model_type', $modelType);
    }

    /**
     * Scope for specific action
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope for date range
     */
    public function scopeDateRange($query, $startDate, $endDate = null)
    {
        if ($endDate === null) {
            $endDate = now();
        }

        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope for specific causer
     */
    public function scopeCauser($query, $causerType, $causerId = null)
    {
        $query->where('causer_type', $causerType);

        if ($causerId !== null) {
            $query->where('causer_id', $causerId);
        }

        return $query;
    }
}