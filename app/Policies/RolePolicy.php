<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->hasRole(Role::SUPER_ADMIN);
    }

    public function view(AuthUser $authUser, Role $role): bool
    {
        return false;
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        return $authUser->hasRole(Role::SUPER_ADMIN);
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        return $authUser->hasRole(Role::SUPER_ADMIN)
            && !in_array($role->name, Role::PANEL_ROLES, true);
    }
}
