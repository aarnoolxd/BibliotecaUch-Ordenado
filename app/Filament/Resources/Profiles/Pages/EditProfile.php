<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\EditRecord;
use App\Filament\Resources\Profiles\ProfileResource;
use App\Filament\Resources\Profiles\Schemas\ProfileForm;

class EditProfile extends EditRecord
{
    protected static string $resource = ProfileResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ProfileForm::normalizeAcademicData($data);
    }

    protected function getSaveConfirmationDescription(): string
    {
        return 'Se actualizarán los datos del perfil.';
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Perfil actualizado';
    }
}
