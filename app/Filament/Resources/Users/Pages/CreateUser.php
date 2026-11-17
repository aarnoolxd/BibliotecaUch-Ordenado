<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->requiresConfirmation()
            ->modalHeading('Confirmar creación de cuenta')
            ->modalDescription('¿Está seguro de que desea registrar esta nueva cuenta de usuario con los datos ingresados?')
            ->modalSubmitActionLabel('Confirmar y Crear');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Cuenta creada exitosamente';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
