<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Filament\Resources\Equipment\EquipmentResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateEquipment extends CreateRecord
{
    protected static string $resource = EquipmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Criterio 4 / UB-70: Asignar de manera predeterminada el estado "Disponible" al guardar
        $data['status'] = 'Disponible';

        return $data;
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->requiresConfirmation()
            ->modalHeading('Confirmar registro de nuevo equipo')
            ->modalDescription('¿Está seguro de que desea registrar este recurso en el inventario con el estado inicial Disponible?')
            ->modalSubmitActionLabel('Confirmar y Registrar');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        // Criterio 5 / UB-71: Notificación de éxito en pantalla
        return 'Equipo registrado exitosamente en el catálogo';
    }

    protected function getRedirectUrl(): string
    {
        // Refrescar y listar inmediatamente el nuevo equipo en el catálogo
        return $this->getResource()::getUrl('index');
    }
}
