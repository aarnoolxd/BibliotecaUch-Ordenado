<?php

namespace App\Filament\Resources\Borrowings\Pages;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Equipment;
use App\Models\Profile;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateBorrowing extends CreateRecord
{
    protected static string $resource = BorrowingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Subtarea [UB-145] / Criterio 2: Validar existencia del DNI en el sistema
        $profile = Profile::where('dni', trim($data['student_dni'] ?? ''))->first();
        if (! $profile) {
            Notification::make()
                ->danger()
                ->title('DNI no encontrado')
                ->body('El DNI ingresado no existe en el sistema. Debe registrar previamente el perfil del estudiante.')
                ->persistent()
                ->send();

            throw ValidationException::withMessages([
                'student_dni' => 'El DNI ingresado no existe en el sistema.',
            ]);
        }

        $data['profile_id'] = $profile->id;

        // Subtarea [UB-142] / Criterio 3: Verificar que el recurso seleccionado esté en estado "Disponible"
        $equipment = Equipment::find($data['equipment_id'] ?? null);
        if (! $equipment) {
            Notification::make()
                ->danger()
                ->title('Recurso inválido')
                ->body('El recurso seleccionado no existe en el catálogo.')
                ->send();

            throw ValidationException::withMessages([
                'equipment_id' => 'El recurso seleccionado no existe.',
            ]);
        }

        if ($equipment->status !== 'Disponible') {
            Notification::make()
                ->danger()
                ->title('Recurso no disponible')
                ->body("El recurso seleccionado no se encuentra disponible (Estado actual: {$equipment->status}). No se puede registrar el préstamo.")
                ->persistent()
                ->send();

            throw ValidationException::withMessages([
                'equipment_id' => "El recurso '{$equipment->brand} {$equipment->model}' no está Disponible (Estado: {$equipment->status}).",
            ]);
        }

        // Subtarea [UB-141] / Criterio 4: Fecha de préstamo y cálculo de fecha límite de devolución
        $borrowingDate = ! empty($data['borrowing_date']) ? Carbon::parse($data['borrowing_date']) : now();
        $data['borrowing_date'] = $borrowingDate->toDateTimeString();

        if (empty($data['due_date'])) {
            $data['due_date'] = match ($equipment->type) {
                'Laptop'      => $borrowingDate->copy()->addDays(2)->toDateTimeString(),
                'Computadora' => $borrowingDate->copy()->addHours(6)->toDateTimeString(),
                'Periférico'  => $borrowingDate->copy()->addDay()->toDateTimeString(),
                default       => $borrowingDate->copy()->addDay()->toDateTimeString(),
            };
        }

        // Subtarea [UB-144] / Criterio 5: Asignar automáticamente el estado "En curso" al préstamo
        $data['status'] = 'En curso';
        $data['registered_by'] = Auth::id();

        return $data;
    }

    protected function afterCreate(): void
    {
        // Actualizar el estado operativo del equipo prestado a 'Prestado'
        /** @var \App\Models\Borrowing $record */
        $record = $this->record;
        if ($record && $record->equipment) {
            $record->equipment->update([
                'status' => 'Prestado',
            ]);
        }
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->requiresConfirmation()
            ->modalHeading('Confirmar registro de préstamo')
            ->modalDescription('¿Está seguro de que desea registrar este préstamo? El recurso pasará al estado "Prestado" y quedará asignado al estudiante.')
            ->modalSubmitActionLabel('Confirmar y Registrar Préstamo');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        // Subtarea [UB-144] / Criterio 5: Notificación de confirmación en pantalla
        return 'Préstamo registrado exitosamente en estado "En curso"';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
