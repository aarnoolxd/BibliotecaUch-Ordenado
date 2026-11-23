<?php

namespace App\Filament\Resources\Borrowings\Pages;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Borrowing;
use App\Models\Equipment;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditBorrowing extends EditRecord
{
    protected static string $resource = BorrowingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Sincroniza atómicamente los estados de los equipos cuando se reasigna
     * el equipo de un préstamo activo dentro de la transacción de guardado.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Borrowing $record */
        $previousEquipmentId = (int) $record->equipment_id;
        $nextEquipmentId = (int) ($data['equipment_id'] ?? $previousEquipmentId);
        $isActiveBorrowing = in_array($record->status, Borrowing::ACTIVE_STATUSES, true);

        if ($isActiveBorrowing && $nextEquipmentId !== $previousEquipmentId) {
            $newEquipment = Equipment::query()
                ->lockForUpdate()
                ->find($nextEquipmentId);

            if ($newEquipment === null || $newEquipment->status !== 'Disponible') {
                Notification::make()
                    ->danger()
                    ->title('Recurso no disponible')
                    ->body('El nuevo recurso seleccionado no se encuentra disponible para reasignar el préstamo.')
                    ->persistent()
                    ->send();

                throw ValidationException::withMessages([
                    'data.equipment_id' => 'El recurso seleccionado no se encuentra disponible.',
                ]);
            }

            $claimed = Equipment::query()
                ->whereKey($nextEquipmentId)
                ->where('status', 'Disponible')
                ->update(['status' => 'Prestado']);

            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'data.equipment_id' => 'El recurso seleccionado ya no está Disponible.',
                ]);
            }
        }

        $updatedRecord = parent::handleRecordUpdate($record, $data);

        if ($isActiveBorrowing && $nextEquipmentId !== $previousEquipmentId) {
            Borrowing::releaseEquipmentIfNoActiveBorrowings(
                equipmentId: $previousEquipmentId,
                ignoreBorrowingId: (int) $updatedRecord->getKey(),
            );
        }

        return $updatedRecord;
    }
}
