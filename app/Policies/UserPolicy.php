<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Auth\Access\HandlesAuthorization;

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
        return $authUser->can('Update_User');
    }

    public function delete(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('Delete_User');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny_User');
    }

    public function restore(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('Restore_User');
    }

    public function forceDelete(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('ForceDelete_User');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny_User');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny_User');
    }

    public function replicate(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('Replicate_User');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder_User');
    }
}
