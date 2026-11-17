<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Profile;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProfilePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny_Profile');
    }

    public function view(AuthUser $authUser, Profile $profile): bool
    {
        return $authUser->can('View_Profile');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create_Profile');
    }

    public function update(AuthUser $authUser, Profile $profile): bool
    {
        return $authUser->can('Update_Profile');
    }

    public function delete(AuthUser $authUser, Profile $profile): bool
    {
        return $authUser->can('Delete_Profile');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny_Profile');
    }

    public function restore(AuthUser $authUser, Profile $profile): bool
    {
        return $authUser->can('Restore_Profile');
    }

    public function forceDelete(AuthUser $authUser, Profile $profile): bool
    {
        return $authUser->can('ForceDelete_Profile');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny_Profile');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny_Profile');
    }

    public function replicate(AuthUser $authUser, Profile $profile): bool
    {
        return $authUser->can('Replicate_Profile');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder_Profile');
    }
}
