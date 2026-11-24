<?php

namespace App\Filament\Resources\Borrowings\Pages;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Profile;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditBorrowing extends EditRecord
{
    protected static string $resource = BorrowingResource::class;

    /**
     * IDs de dispositivos adicionales validados para sincronizar en handleRecordUpdate.
     *
     * @var list<int>
     */
    protected array $additionalEquipmentIds = [];

    /**
     * Fecha límite enviada originalmente por el formulario antes de normalizar según tipo.
     */
    protected ?string $submittedDueDate = null;

    protected function getHeaderActions(): array
    {
        return [
            BorrowingResource::borrowingDetailsAction(),
        ];
    }

    /**
     * Sincroniza profile_id, valida equipos principales/adicionales y aplica la regla
     * de fecha límite según si el equipo principal es una Computadora.
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

        $rawAdditionalIds = is_array($data['additional_equipment_ids'] ?? null) ? $data['additional_equipment_ids'] : [];
        unset($data['additional_equipment_ids']);

        /** @var Borrowing $currentRecord */
        $currentRecord = $this->record;

        if ($currentRecord->status !== Borrowing::STATUS_IN_PROGRESS) {
            Notification::make()
                ->danger()
                ->title('Edición no permitida')
                ->body('Solo se pueden editar préstamos que se encuentran en estado "En curso".')
                ->send();

            throw ValidationException::withMessages([
                'data.student_dni' => 'Solo se pueden editar préstamos en estado "En curso".',
            ]);
        }

        $primaryEquipmentId = filled($data['equipment_id'] ?? null) ? (int) $data['equipment_id'] : null;
        $additionalIds = array_values(array_unique(array_map(
            'intval',
            array_filter($rawAdditionalIds, fn (mixed $id): bool => filled($id)),
        )));
        sort($additionalIds);

        if ($primaryEquipmentId === null && $additionalIds === []) {
            throw ValidationException::withMessages([
                'data.equipment_id' => 'Debe seleccionar al menos un equipo principal o un dispositivo adicional para registrar el préstamo.',
            ]);
        }

        $primaryEquipment = null;

        if ($primaryEquipmentId !== null) {
            $primaryEquipment = Equipment::query()->find($primaryEquipmentId);

            if ($primaryEquipment === null) {
                throw ValidationException::withMessages([
                    'data.equipment_id' => 'El recurso seleccionado no existe.',
                ]);
            }

            if (! in_array($primaryEquipment->type, Equipment::PRIMARY_BORROWING_TYPES, true)) {
                throw ValidationException::withMessages([
                    'data.equipment_id' => 'Solo puede seleccionar una Computadora o Laptop como equipo principal.',
                ]);
            }
        }

        if ($additionalIds !== []) {
            $additionalModels = Equipment::query()->whereIn('id', $additionalIds)->get()->keyBy('id');

            foreach ($additionalIds as $additionalId) {
                /** @var Equipment|null $additional */
                $additional = $additionalModels->get($additionalId);

                if ($additional === null) {
                    throw ValidationException::withMessages([
                        'data.additional_equipment_ids' => 'Uno de los dispositivos adicionales seleccionados no existe en el catálogo.',
                    ]);
                }

                if (! in_array($additional->type, Equipment::ADDITIONAL_BORROWING_TYPES, true)) {
                    throw ValidationException::withMessages([
                        'data.additional_equipment_ids' => "El recurso '{$additional->brand} {$additional->model}' no pertenece a la categoría Periférico u Otro.",
                    ]);
                }
            }
        }

        $this->additionalEquipmentIds = $additionalIds;
        $this->submittedDueDate = filled($data['due_date'] ?? null) ? (string) $data['due_date'] : null;
        $data['equipment_id'] = $primaryEquipmentId;

        $borrowingDate = filled($data['borrowing_date'] ?? null)
            ? Carbon::parse($data['borrowing_date'])
            : ($currentRecord->borrowing_date ?? now());

        if ($primaryEquipment?->type === 'Computadora') {
            $dueDate = filled($data['due_date'] ?? null)
                ? Carbon::parse($data['due_date'])
                : Borrowing::calculateDueDate('Computadora', $borrowingDate);

            if ($dueDate !== null && $dueDate->lt($borrowingDate)) {
                throw ValidationException::withMessages([
                    'data.due_date' => 'La fecha límite de devolución no puede ser anterior a la fecha del préstamo.',
                ]);
            }

            $data['due_date'] = $dueDate?->toDateTimeString();
        } else {
            $data['due_date'] = null;
        }

        return $data;
    }

    /**
     * Sincroniza atómicamente los estados del equipo principal y dispositivos adicionales
     * cuando se modifican los recursos de un préstamo activo dentro de la transacción.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Borrowing $lockedRecord */
        $lockedRecord = Borrowing::query()
            ->with('additionalEquipment')
            ->lockForUpdate()
            ->findOrFail($record->getKey());

        if ($lockedRecord->status !== Borrowing::STATUS_IN_PROGRESS) {
            throw ValidationException::withMessages([
                'data.student_dni' => 'Solo se pueden editar préstamos en estado "En curso".',
            ]);
        }

        $previousIds = $lockedRecord->allEquipmentIds();
        $nextIds = $this->additionalEquipmentIds;

        if ($data['equipment_id'] !== null) {
            $nextIds[] = (int) $data['equipment_id'];
        }

        $nextIds = array_values(array_unique($nextIds));
        sort($nextIds);

        $idsToClaim = array_values(array_diff($nextIds, $previousIds));
        $idsToRelease = array_values(array_diff($previousIds, $nextIds));

        $allTouchedIds = array_values(array_unique(array_merge($previousIds, $nextIds)));
        sort($allTouchedIds);

        if ($allTouchedIds !== []) {
            $lockedEquipments = Equipment::query()
                ->whereIn('id', $allTouchedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Equipment|null $lockedPrimaryEquipment */
            $lockedPrimaryEquipment = $data['equipment_id'] !== null
                ? $lockedEquipments->get((int) $data['equipment_id'])
                : null;

            if ($data['equipment_id'] !== null) {
                if ($lockedPrimaryEquipment === null) {
                    throw ValidationException::withMessages([
                        'data.equipment_id' => 'El recurso seleccionado no existe.',
                    ]);
                }

                if (! in_array($lockedPrimaryEquipment->type, Equipment::PRIMARY_BORROWING_TYPES, true)) {
                    throw ValidationException::withMessages([
                        'data.equipment_id' => 'Solo puede seleccionar una Computadora o Laptop como equipo principal.',
                    ]);
                }
            }

            foreach ($this->additionalEquipmentIds as $additionalId) {
                /** @var Equipment|null $lockedAdditional */
                $lockedAdditional = $lockedEquipments->get($additionalId);

                if ($lockedAdditional === null) {
                    throw ValidationException::withMessages([
                        'data.additional_equipment_ids' => 'Uno de los dispositivos adicionales seleccionados no existe en el catálogo.',
                    ]);
                }

                if (! in_array($lockedAdditional->type, Equipment::ADDITIONAL_BORROWING_TYPES, true)) {
                    throw ValidationException::withMessages([
                        'data.additional_equipment_ids' => "El recurso '{$lockedAdditional->brand} {$lockedAdditional->model}' no pertenece a la categoría Periférico u Otro.",
                    ]);
                }
            }

            $effectiveBorrowingDate = filled($data['borrowing_date'] ?? null)
                ? Carbon::parse($data['borrowing_date'])
                : ($lockedRecord->borrowing_date ?? now());

            if ($lockedPrimaryEquipment?->type === 'Computadora') {
                $rawDueDate = filled($data['due_date'] ?? null) ? $data['due_date'] : $this->submittedDueDate;
                $effectiveDueDate = filled($rawDueDate)
                    ? Carbon::parse($rawDueDate)
                    : Borrowing::calculateDueDate('Computadora', $effectiveBorrowingDate);

                if ($effectiveDueDate !== null && $effectiveDueDate->lt($effectiveBorrowingDate)) {
                    throw ValidationException::withMessages([
                        'data.due_date' => 'La fecha límite de devolución no puede ser anterior a la fecha del préstamo.',
                    ]);
                }

                $data['due_date'] = $effectiveDueDate?->toDateTimeString();
            } else {
                $data['due_date'] = null;
            }

            foreach ($idsToClaim as $equipmentId) {
                /** @var Equipment|null $newEquipment */
                $newEquipment = $lockedEquipments->get($equipmentId);

                if ($newEquipment === null || $newEquipment->status !== 'Disponible') {
                    Notification::make()
                        ->danger()
                        ->title('Recurso no disponible')
                        ->body('El nuevo recurso seleccionado no se encuentra disponible para reasignar el préstamo.')
                        ->persistent()
                        ->send();

                    $errorField = ((int) ($data['equipment_id'] ?? 0) === $equipmentId)
                        ? 'data.equipment_id'
                        : 'data.additional_equipment_ids';

                    throw ValidationException::withMessages([
                        $errorField => 'El recurso seleccionado no se encuentra disponible.',
                    ]);
                }
            }

            if ($idsToClaim !== []) {
                $claimed = Equipment::query()
                    ->whereIn('id', $idsToClaim)
                    ->where('status', 'Disponible')
                    ->update(['status' => 'Prestado']);

                if ($claimed !== count($idsToClaim)) {
                    $errorField = in_array((int) ($data['equipment_id'] ?? 0), $idsToClaim, true)
                        ? 'data.equipment_id'
                        : 'data.additional_equipment_ids';

                    throw ValidationException::withMessages([
                        $errorField => 'Alguno de los recursos seleccionados ya no está Disponible.',
                    ]);
                }
            }
        }

        /** @var Borrowing $updatedRecord */
        $updatedRecord = parent::handleRecordUpdate($lockedRecord, $data);
        $updatedRecord->additionalEquipment()->sync($this->additionalEquipmentIds);

        foreach ($idsToRelease as $equipmentId) {
            Borrowing::releaseEquipmentIfNoActiveBorrowings(
                equipmentId: $equipmentId,
                ignoreBorrowingId: (int) $updatedRecord->getKey(),
            );
        }

        $record->setRawAttributes($updatedRecord->getAttributes(), sync: true);

        return $updatedRecord;
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Préstamo actualizado correctamente')
            ->body('Los cambios del préstamo y sus dispositivos asignados se guardaron con éxito.');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
