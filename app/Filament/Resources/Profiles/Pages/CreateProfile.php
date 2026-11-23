<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\CreateRecord;
use App\Filament\Resources\Profiles\ProfileResource;
use Filament\Actions\Action;

class CreateProfile extends CreateRecord
{
    protected static string $resource = ProfileResource::class;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->requiresConfirmation()
            ->modalHeading('Confirmar registro de metadatos del perfil')
            ->modalDescription('¿Está seguro de que desea registrar estos metadatos personales para el perfil con los datos ingresados?')
            ->modalSubmitActionLabel('Confirmar y Registrar');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Metadatos personales del perfil registrados exitosamente';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
