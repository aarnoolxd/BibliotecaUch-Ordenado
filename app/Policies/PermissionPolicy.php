<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Permission;
use Illuminate\Auth\Access\HandlesAuthorization;

class PermissionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny_Permission');
    }

    public function view(AuthUser $authUser, Permission $permission): bool
    {
        return $authUser->can('View_Permission');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create_Permission');
    }

    public function update(AuthUser $authUser, Permission $permission): bool
    {
        return $authUser->can('Update_Permission');
    }

    public function delete(AuthUser $authUser, Permission $permission): bool
    {
        return $authUser->can('Delete_Permission');
    }

}