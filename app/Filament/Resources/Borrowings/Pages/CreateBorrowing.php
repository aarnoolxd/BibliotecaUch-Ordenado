<?php

namespace App\Filament\Resources\Borrowings\Pages;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Filament\Resources\CreateRecord;
use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Profile;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateBorrowing extends CreateRecord
{
    protected static string $resource = BorrowingResource::class;

    /**
     * IDs de dispositivos adicionales validados para sincronizar tras crear el préstamo.
     *
     * @var list<int>
     */
    protected array $additionalEquipmentIds = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $profile = Profile::query()->where('dni', trim((string) ($data['student_dni'] ?? '')))->first();

        if ($profile === null) {
            Notification::make()
                ->danger()
                ->title('DNI no encontrado')
                ->body('Registra primero el perfil del estudiante.')
                ->persistent()
                ->send();

            throw ValidationException::withMessages([
                'data.student_dni' => 'El DNI ingresado no existe en el sistema.',
            ]);
        }

        $data['student_dni'] = $profile->dni;
        $data['profile_id'] = $profile->id;

        $primaryEquipmentId = filled($data['equipment_id'] ?? null) ? (int) $data['equipment_id'] : null;
        $additionalIds = array_values(array_unique(array_map(
            'intval',
            array_filter(is_array($data['additional_equipment_ids'] ?? null) ? $data['additional_equipment_ids'] : [], fn (mixed $id): bool => filled($id)),
        )));
        sort($additionalIds);
        unset($data['additional_equipment_ids']);

        if ($primaryEquipmentId === null && $additionalIds === []) {
            throw ValidationException::withMessages([
                'data.equipment_id' => 'Debe seleccionar al menos un equipo principal o un dispositivo adicional para registrar el préstamo.',
            ]);
        }

        $allIds = $additionalIds;
        if ($primaryEquipmentId !== null) {
            $allIds[] = $primaryEquipmentId;
        }
        $allIds = array_values(array_unique($allIds));
        sort($allIds);

        $lockedEquipments = Equipment::query()
            ->whereIn('id', $allIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $primaryEquipment = null;

        if ($primaryEquipmentId !== null) {
            /** @var Equipment|null $primaryEquipment */
            $primaryEquipment = $lockedEquipments->get($primaryEquipmentId);

            if ($primaryEquipment === null) {
                Notification::make()
                    ->danger()
                    ->title('Equipo no encontrado')
                    ->body('El equipo seleccionado ya no existe.')
                    ->send();

                throw ValidationException::withMessages([
                    'data.equipment_id' => 'El recurso seleccionado no existe.',
                ]);
            }

            if (! in_array($primaryEquipment->type, Equipment::PRIMARY_BORROWING_TYPES, true)) {
                throw ValidationException::withMessages([
                    'data.equipment_id' => 'Solo puede seleccionar una Computadora o Laptop como equipo principal.',
                ]);
            }

            if ($primaryEquipment->status !== 'Disponible') {
                Notification::make()
                    ->danger()
                    ->title('Equipo no disponible')
                    ->body("Estado actual: {$primaryEquipment->status}.")
                    ->persistent()
                    ->send();

                throw ValidationException::withMessages([
                    'data.equipment_id' => "El recurso '{$primaryEquipment->brand} {$primaryEquipment->model}' no está Disponible (Estado: {$primaryEquipment->status}).",
                ]);
            }
        }

        foreach ($additionalIds as $additionalId) {
            /** @var Equipment|null $additional */
            $additional = $lockedEquipments->get($additionalId);

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

            if ($additional->status !== 'Disponible') {
                Notification::make()
                    ->danger()
                    ->title('Dispositivo no disponible')
                    ->body("{$additional->brand} {$additional->model} está {$additional->status}.")
                    ->persistent()
                    ->send();

                throw ValidationException::withMessages([
                    'data.additional_equipment_ids' => "El dispositivo '{$additional->brand} {$additional->model}' no está Disponible (Estado: {$additional->status}).",
                ]);
            }
        }

        $this->additionalEquipmentIds = $additionalIds;

        $borrowingDate = ! empty($data['borrowing_date']) ? Carbon::parse($data['borrowing_date']) : now();
        $data['equipment_id'] = $primaryEquipmentId;
        $data['borrowing_date'] = $borrowingDate->toDateTimeString();
        $data['due_date'] = Borrowing::calculateDueDate($primaryEquipment?->type, $borrowingDate)?->toDateTimeString();
        $data['status'] = Borrowing::STATUS_IN_PROGRESS;
        $data['registered_by'] = Auth::id();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $allIds = $this->additionalEquipmentIds;

        if ($data['equipment_id'] !== null) {
            $allIds[] = (int) $data['equipment_id'];
        }

        $allIds = array_values(array_unique($allIds));
        sort($allIds);

        $claimed = Equipment::query()
            ->whereIn('id', $allIds)
            ->where('status', 'Disponible')
            ->update(['status' => 'Prestado']);

        if ($claimed !== count($allIds)) {
            Notification::make()
                ->danger()
                ->title('Equipo no disponible')
                ->body('Un equipo dejó de estar disponible.')
                ->persistent()
                ->send();

            $errorField = $data['equipment_id'] !== null ? 'data.equipment_id' : 'data.additional_equipment_ids';

            throw ValidationException::withMessages([
                $errorField => 'Alguno de los recursos seleccionados ya no está Disponible.',
            ]);
        }

        /** @var Borrowing $borrowing */
        $borrowing = parent::handleRecordCreation($data);

        if ($this->additionalEquipmentIds !== []) {
            $borrowing->additionalEquipment()->sync($this->additionalEquipmentIds);
        }

        return $borrowing;
    }

    protected function getCreateConfirmationHeading(): string
    {
        return '¿Registrar préstamo?';
    }

    protected function getCreateConfirmationDescription(): string
    {
        return 'Los equipos pasarán a estado Prestado.';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Préstamo registrado';
    }
}
