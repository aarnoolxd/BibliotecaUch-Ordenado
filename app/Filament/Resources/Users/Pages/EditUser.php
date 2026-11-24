<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Cuenta actualizada correctamente')
            ->body('Los datos y roles de la cuenta se guardaron con éxito.');
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
