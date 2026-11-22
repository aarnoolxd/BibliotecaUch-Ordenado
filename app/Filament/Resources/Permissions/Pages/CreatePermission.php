<?php

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Resources\Permissions\PermissionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreatePermission extends CreateRecord
{
    protected static string $resource = PermissionResource::class;

    protected function getCreateAnotherFormAction(): Action
    {
        return parent::getCreateAnotherFormAction()
            ->label('Crear otro');
    }
    // Mensaje de notificación amigable al crear
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Permiso creado exitosamente';
    }

    // Redirección automática al listado (index) tras la creación
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
