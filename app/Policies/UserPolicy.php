<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny_User');
    }

    public function view(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('View_User');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create_User');
    }

    public function update(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('Update_User') && ! $user->hasRole(Role::SUPER_ADMIN);
    }

    public function delete(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('Delete_User')
            && ! $authUser->is($user)
            && ! $user->hasRole(Role::SUPER_ADMIN);
    }
}
