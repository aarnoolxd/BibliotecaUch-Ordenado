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
        return $authUser->can('ViewAny_Role');
    }

    public function view(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('View_Role');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create_Role');
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Update_Role');
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        return $authUser->can('Delete_Role');
    }


}
