<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Filament\Resources\Equipment\EquipmentResource;
use App\Models\Equipment;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class EditEquipment extends EditRecord
{
    protected static string $resource = EquipmentResource::class;

    #[Locked]
    public ?string $loadedStatus = null;

    #[Locked]
    public ?string $loadedObservations = null;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function afterFill(): void
    {
        /** @var Equipment $record */
        $record = $this->getRecord();

        $this->loadedStatus = $record->status;
        $this->loadedObservations = $record->observations;
    }

    /**
     * Verifica de forma atómica que el estado y las observaciones no hayan cambiado desde
     * la carga del formulario antes de actualizar el registro, evitando sobrescribir una baja.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $lockedEquipment = Equipment::query()
                ->lockForUpdate()
                ->find($record->getKey());

            if (
                $lockedEquipment === null
                || $lockedEquipment->status !== $this->loadedStatus
                || $lockedEquipment->observations !== $this->loadedObservations
            ) {
                Notification::make()
                    ->title('El registro fue modificado')
                    ->body('El estado o las observaciones de este equipo cambiaron desde que cargaste el formulario. Recarga el registro para ver los datos actuales antes de guardar.')
                    ->danger()
                    ->persistent()
                    ->send();

                $this->halt();
            }

            $lockedEquipment->update($data);

            $record->setRawAttributes($lockedEquipment->getAttributes(), sync: true);

            $this->loadedStatus = $lockedEquipment->status;
            $this->loadedObservations = $lockedEquipment->observations;

            return $record;
        });
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Equipo actualizado exitosamente';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
