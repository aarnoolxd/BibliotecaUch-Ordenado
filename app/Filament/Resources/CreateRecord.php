<?php

namespace App\Filament\Resources;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord as BaseCreateRecord;

abstract class CreateRecord extends BaseCreateRecord
{
    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('clearFields')
                ->label('Limpiar')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('¿Limpiar los campos del formulario?')
                ->modalDescription('Se borrarán todos los datos ingresados en este formulario. Esta acción no se puede deshacer.')
                ->modalSubmitActionLabel('Sí, limpiar campos')
                ->modalCancelActionLabel('Cancelar')
                ->action(function (): void {
                    $this->form->fill([]);
                    $this->resetValidation();

                    Notification::make()
                        ->title('Campos limpiados exitosamente')
                        ->body('Puedes ingresar nuevos datos en el formulario.')
                        ->success()
                        ->send();
                }),
            $this->getCancelFormAction()
                ->extraAttributes(['style' => 'margin-inline-start: auto']),
        ];
    }
}
