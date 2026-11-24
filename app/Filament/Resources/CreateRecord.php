<?php

namespace App\Filament\Resources;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord as BaseCreateRecord;
use Illuminate\Validation\ValidationException;

abstract class CreateRecord extends BaseCreateRecord
{
    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->submit(null)
            ->mountUsing(function (): void {
                $this->form->validate();
            })
            ->requiresConfirmation()
            ->modalHeading($this->getCreateConfirmationHeading())
            ->modalDescription($this->getCreateConfirmationDescription())
            ->modalSubmitActionLabel($this->getCreateConfirmationSubmitLabel())
            ->modalCancelActionLabel('Cancelar')
            ->action(function (): void {
                try {
                    $this->create();
                } catch (ValidationException $exception) {
                    $this->unmountAction();

                    throw $exception;
                }
            });
    }

    protected function getCreateConfirmationHeading(): string
    {
        return '¿Registrar elemento?';
    }

    protected function getCreateConfirmationDescription(): string
    {
        return 'Se guardará este registro.';
    }

    protected function getCreateConfirmationSubmitLabel(): string
    {
        return 'Registrar';
    }

    protected function getRedirectUrl(): string
    {
        $resource = $this->getResource();

        if ($resource::canViewAny()) {
            return $resource::getUrl('index');
        }

        return parent::getRedirectUrl();
    }

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
                ->modalHeading('¿Limpiar formulario?')
                ->modalDescription('Se borrarán los datos ingresados.')
                ->modalSubmitActionLabel('Limpiar')
                ->modalCancelActionLabel('Cancelar')
                ->action(function (): void {
                    $this->form->fill([]);
                    $this->resetValidation();

                    Notification::make()
                        ->title('Formulario limpiado')
                        ->success()
                        ->send();
                }),
            $this->getCancelFormAction()
                ->extraAttributes(['style' => 'margin-inline-start: auto']),
        ];
    }
}
