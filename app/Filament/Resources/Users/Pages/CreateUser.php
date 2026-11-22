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
            ->submit(null)
            ->requiresConfirmation()
            ->modalHeading('Confirmar creación de cuenta')
            ->modalDescription('¿Está seguro de que desea registrar esta nueva cuenta de usuario con los datos ingresados?')
            ->modalSubmitActionLabel('Confirmar y Crear')
            ->action(fn () => $this->create());
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->requiresConfirmation()
            ->modalHeading('Confirmar creación de cuenta')
            ->modalDescription('¿Está seguro de que desea registrar esta nueva cuenta de usuario y continuar creando otra?')
            ->modalSubmitActionLabel('Confirmar y Crear otra');
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
