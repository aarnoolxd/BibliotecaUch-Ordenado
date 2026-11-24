<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\EditRecord;
use App\Filament\Resources\Users\UserResource;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getSaveConfirmationDescription(): string
    {
        return 'Se actualizarán los datos y roles.';
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Cuenta actualizada';
    }
}
