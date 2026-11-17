<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Borrowing;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Auth\Access\HandlesAuthorization;

class BorrowingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny_Borrowing');
    }

    public function view(AuthUser $authUser, Borrowing $borrowing): bool
    {
        return $authUser->can('View_Borrowing');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create_Borrowing');
    }

    public function update(AuthUser $authUser, Borrowing $borrowing): bool
    {
        return $authUser->can('Update_Borrowing');
    }

    public function delete(AuthUser $authUser, Borrowing $borrowing): bool
    {
        return $authUser->can('Delete_Borrowing');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny_Borrowing');
    }

    public function restore(AuthUser $authUser, Borrowing $borrowing): bool
    {
        return $authUser->can('Restore_Borrowing');
    }

    public function forceDelete(AuthUser $authUser, Borrowing $borrowing): bool
    {
        return $authUser->can('ForceDelete_Borrowing');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny_Borrowing');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny_Borrowing');
    }

    public function replicate(AuthUser $authUser, Borrowing $borrowing): bool
    {
        return $authUser->can('Replicate_Borrowing');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder_Borrowing');
    }
}
