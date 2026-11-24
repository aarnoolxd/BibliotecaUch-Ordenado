<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Filament\Resources\CreateRecord;
use App\Filament\Resources\Equipment\EquipmentResource;

class CreateEquipment extends CreateRecord
{
    protected static string $resource = EquipmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Criterio 4 / UB-70: Asignar de manera predeterminada el estado "Disponible" al guardar
        $data['status'] = 'Disponible';

        return $data;
    }

    protected function getCreateConfirmationHeading(): string
    {
        return '¿Registrar equipo?';
    }

    protected function getCreateConfirmationDescription(): string
    {
        return 'Ingresará al inventario como Disponible.';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        // Criterio 5 / UB-71: Notificación de éxito en pantalla
        return 'Equipo registrado';
    }
}
