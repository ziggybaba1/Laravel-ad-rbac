<?php

namespace LaravelAdRbac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use SoftDeletes;
    protected $table = 'permissions';

    protected $fillable = [
        'name',
        'action',
        'module',
        'slug',
        'description',
    ];

    protected $casts = [
        'is_system' => 'boolean'
    ];

    /**
     * Get the roles that have this permission
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withTimestamps();
    }
}