<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PermissionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->hasRole(Role::SUPER_ADMIN);
    }

    public function view(AuthUser $authUser, Permission $permission): bool
    {
        return false;
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, Permission $permission): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, Permission $permission): bool
    {
        return false;
    }
}
