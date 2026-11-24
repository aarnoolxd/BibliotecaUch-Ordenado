<?php

namespace App\Filament\Resources;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord as BaseEditRecord;
use Illuminate\Validation\ValidationException;

abstract class EditRecord extends BaseEditRecord
{
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->submit(null)
            ->mountUsing(function (): void {
                $this->form->validate();
            })
            ->requiresConfirmation()
            ->modalHeading($this->getSaveConfirmationHeading())
            ->modalDescription($this->getSaveConfirmationDescription())
            ->modalSubmitActionLabel($this->getSaveConfirmationSubmitLabel())
            ->modalCancelActionLabel('Cancelar')
            ->action(function (): void {
                try {
                    $this->save();
                } catch (ValidationException $exception) {
                    $this->unmountAction();

                    throw $exception;
                }
            });
    }

    protected function getSaveConfirmationHeading(): string
    {
        return '¿Guardar cambios?';
    }

    protected function getSaveConfirmationDescription(): string
    {
        return 'Se actualizará este registro.';
    }

    protected function getSaveConfirmationSubmitLabel(): string
    {
        return 'Guardar';
    }

    protected function getRedirectUrl(): ?string
    {
        $resource = $this->getResource();

        if ($resource::canViewAny()) {
            return $resource::getUrl('index');
        }

        return parent::getRedirectUrl();
    }
}
