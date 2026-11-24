<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\CreateRecord;
use App\Filament\Resources\Profiles\ProfileResource;
use App\Filament\Resources\Profiles\Schemas\ProfileForm;

class CreateProfile extends CreateRecord
{
    protected static string $resource = ProfileResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return ProfileForm::normalizeAcademicData($data);
    }

    protected function getCreateConfirmationHeading(): string
    {
        return '¿Registrar perfil?';
    }

    protected function getCreateConfirmationDescription(): string
    {
        return 'Se guardarán los datos del perfil.';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Perfil registrado';
    }
}
