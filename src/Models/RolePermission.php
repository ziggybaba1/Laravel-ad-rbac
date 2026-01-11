<?php

namespace LaravelAdRbac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RolePermission extends Model
{
    use SoftDeletes;
    protected $table = 'role_permission';

    protected $fillable = [
        'role_id',
        'permission_id',
    ];


}