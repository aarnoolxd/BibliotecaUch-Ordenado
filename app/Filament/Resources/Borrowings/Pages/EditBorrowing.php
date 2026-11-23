<?php

namespace App\Filament\Resources\Borrowings\Pages;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Profile;
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
     * Sincroniza profile_id a partir del student_dni enviado al editar el préstamo,
     * sin depender de eventos reactivos del cliente.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $dni = trim((string) ($data['student_dni'] ?? ''));
        $profile = Profile::query()->where('dni', $dni)->first();

        if ($profile === null) {
            Notification::make()
                ->danger()
                ->title('DNI no encontrado')
                ->body('El DNI ingresado no existe en el sistema. Debe registrar previamente el perfil del estudiante.')
                ->persistent()
                ->send();

            throw ValidationException::withMessages([
                'data.student_dni' => 'El DNI ingresado no existe en el sistema.',
            ]);
        }

        $data['student_dni'] = $profile->dni;
        $data['profile_id'] = $profile->getKey();

        return $data;
    }

    /**
     * Sincroniza atómicamente los estados de los equipos cuando se reasigna
     * el equipo de un préstamo activo dentro de la transacción de guardado.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Borrowing $lockedRecord */
        $lockedRecord = Borrowing::query()
            ->lockForUpdate()
            ->findOrFail($record->getKey());

        $previousEquipmentId = (int) $lockedRecord->equipment_id;
        $nextEquipmentId = (int) ($data['equipment_id'] ?? $previousEquipmentId);
        $isActiveBorrowing = in_array($lockedRecord->status, Borrowing::ACTIVE_STATUSES, true);

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

        $updatedRecord = parent::handleRecordUpdate($lockedRecord, $data);

        if ($isActiveBorrowing && $nextEquipmentId !== $previousEquipmentId) {
            Borrowing::releaseEquipmentIfNoActiveBorrowings(
                equipmentId: $previousEquipmentId,
                ignoreBorrowingId: (int) $updatedRecord->getKey(),
            );
        }

        $record->setRawAttributes($updatedRecord->getAttributes(), sync: true);

        return $updatedRecord;
    }
}
