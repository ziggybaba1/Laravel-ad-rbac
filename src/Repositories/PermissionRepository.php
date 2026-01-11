<?php

namespace LaravelAdRbac\Repositories;

use LaravelAdRbac\Models\Permission;
use LaravelAdRbac\Models\Role;
use LaravelAdRbac\Models\Group;

class PermissionRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Permission());
    }
}

class RoleRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Role());
    }
}

class GroupRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new Group());
    }
}