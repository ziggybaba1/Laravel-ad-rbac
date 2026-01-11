<?php

// src/Models/Assignment.php
namespace LaravelAdRbac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'assignable_id',
        'assignable_type',
        'assignment_reason',
        'assigned_by',
        'assigned_at',
        'expires_at',
        'is_active',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Relationship: Assignment belongs to an employee
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Relationship: Polymorphic - assignment can be for Group, Role, or Permission
     */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Relationship: Who assigned this
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }

    /**
     * Relationship: Assignment history
     */
    public function history()
    {
        return $this->hasMany(AssignmentHistory::class);
    }

    /**
     * Scope: Active assignments
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope: Expired assignments
     */
    public function scopeExpired($query)
    {
        return $query->where('is_active', true)
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope: By assignable type
     */
    public function scopeType($query, string $type)
    {
        return $query->where('assignable_type', $type);
    }

    /**
     * Scope: Group assignments
     */
    public function scopeGroups($query)
    {
        return $query->type('group');
    }

    /**
     * Scope: Role assignments
     */
    public function scopeRoles($query)
    {
        return $query->type('role');
    }

    /**
     * Scope: Permission assignments
     */
    public function scopePermissions($query)
    {
        return $query->type('permission');
    }

    /**
     * Check if assignment is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Deactivate assignment
     */
    public function deactivate(string $reason = null): void
    {
        $this->update([
            'is_active' => false,
            'assignment_reason' => $reason ?: $this->assignment_reason,
        ]);

        $this->logHistory('deactivated', ['reason' => $reason]);
    }

    /**
     * Extend assignment expiry
     */
    public function extend($days, $changedBy = null): void
    {
        $oldExpiry = $this->expires_at;
        $newExpiry = now()->addDays($days);

        $this->update(['expires_at' => $newExpiry]);

        $this->logHistory('extended', [
            'old_expiry' => $oldExpiry,
            'new_expiry' => $newExpiry,
            'extended_days' => $days,
            'changed_by' => $changedBy,
        ]);
    }

    /**
     * Log changes to history
     */
    protected function logHistory(string $action, array $data = []): void
    {
        AssignmentHistory::create([
            'assignment_id' => $this->id,
            'changes' => json_encode([
                'action' => $action,
                'timestamp' => now()->toISOString(),
                'data' => $data,
            ]),
            'changed_by' => auth()->id(),
        ]);
    }

    /**
     * Get readable assignable type
     */
    public function getReadableTypeAttribute(): string
    {
        return match ($this->assignable_type) {
            'group' => 'Group',
            'role' => 'Role',
            'permission' => 'Permission',
            default => ucfirst($this->assignable_type),
        };
    }
}