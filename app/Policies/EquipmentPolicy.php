<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Equipment;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Auth\Access\HandlesAuthorization;

class EquipmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny_Equipment');
    }

    public function view(AuthUser $authUser, Equipment $equipment): bool
    {
        return $authUser->can('View_Equipment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create_Equipment');
    }

    public function update(AuthUser $authUser, Equipment $equipment): bool
    {
        return $authUser->can('Update_Equipment');
    }

    public function delete(AuthUser $authUser, Equipment $equipment): bool
    {
        return $authUser->can('Delete_Equipment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny_Equipment');
    }

    public function restore(AuthUser $authUser, Equipment $equipment): bool
    {
        return $authUser->can('Restore_Equipment');
    }

    public function forceDelete(AuthUser $authUser, Equipment $equipment): bool
    {
        return $authUser->can('ForceDelete_Equipment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny_Equipment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny_Equipment');
    }

    public function replicate(AuthUser $authUser, Equipment $equipment): bool
    {
        return $authUser->can('Replicate_Equipment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder_Equipment');
    }
}
