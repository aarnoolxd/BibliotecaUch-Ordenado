<?php

namespace App\Filament\Resources\Roles\Schemas;

use BezhanSalleh\FilamentShield\Traits\HasShieldFormComponents;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class RoleForm
{
    use HasShieldFormComponents;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre del Rol')
                    ->disabled(),

                TextInput::make('guard_name')
                    ->label('Grupo de Seguridad')
                    ->disabled(),

                TextEntry::make('permissions_matrix_info')
                    ->label('Matriz de Accesos')
                    ->state('Seleccione a continuación los permisos específicos (por módulo y acción) que tendrá este rol.'),

                static::getShieldFormComponents(),
            ]);
    }
}
