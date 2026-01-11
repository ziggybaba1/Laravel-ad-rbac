<?php

// src/Models/AssignmentHistory.php
namespace LaravelAdRbac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentHistory extends Model
{
    use HasFactory;

    protected $table = 'assignment_history';

    protected $fillable = [
        'assignment_id',
        'changes',
        'changed_by',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    /**
     * Relationship: History belongs to an assignment
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    /**
     * Relationship: Who made the change
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }

    /**
     * Get formatted changes
     */
    public function getFormattedChangesAttribute(): string
    {
        $changes = $this->changes;
        $action = $changes['action'] ?? 'modified';
        $data = $changes['data'] ?? [];

        switch ($action) {
            case 'created':
                return "Assignment created";
            case 'deactivated':
                $reason = $data['reason'] ?? 'No reason provided';
                return "Assignment deactivated. Reason: {$reason}";
            case 'extended':
                $days = $data['extended_days'] ?? 0;
                return "Assignment extended by {$days} days";
            default:
                return "Assignment {$action}";
        }
    }
}