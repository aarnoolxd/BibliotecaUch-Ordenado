<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\CreateRecord;
use App\Filament\Resources\Users\UserResource;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function getCreateConfirmationHeading(): string
    {
        return '¿Crear cuenta?';
    }

    protected function getCreateConfirmationDescription(): string
    {
        return 'Se registrará esta cuenta de usuario.';
    }

    protected function getCreateConfirmationSubmitLabel(): string
    {
        return 'Crear';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Cuenta creada';
    }
}
