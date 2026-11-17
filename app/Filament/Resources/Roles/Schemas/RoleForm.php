<?php

namespace App\Filament\Resources\Roles\Schemas;

use BezhanSalleh\FilamentShield\Traits\HasShieldFormComponents;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Placeholder;

class RoleForm
{
    use HasShieldFormComponents;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre del Rol')
                    ->placeholder('Ej: Administrador, Bibliotecario')
                    ->helperText('Campo para identificar el rol dentro del sistema.')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->minLength(8)
                    ->maxLength(64)
                    ->live()
                    ->validationMessages([
                        'required' => 'El nombre del rol es obligatorio.',
                        'unique'   => 'Este nombre de rol ya está registrado.',
                        'min'      => 'El nombre debe tener al menos 8 caracteres.',
                        'max'      => 'El nombre no puede superar los 64 caracteres.',
                    ]),

                TextInput::make('guard_name')
                    ->label('Grupo de Seguridad')
                    ->placeholder('Ej: web, api')
                    ->helperText('Campo para identificar el grupo de seguridad.')
                    ->default('web')
                    ->required()
                    ->minLength(3)
                    ->maxLength(32)
                    ->live()
                    ->validationMessages([
                        'required' => 'El grupo de seguridad es obligatorio.',
                        'min'      => 'El guard debe tener al menos 3 caracteres.',
                        'max'      => 'El guard no puede superar los 32 caracteres.',
                    ]),

                // Criterio 4: Placeholder que identifica para qué sirve el campo
                Placeholder::make('permissions_matrix_info')
                    ->label('Matriz de Accesos')
                    ->content('Seleccione a continuación los permisos específicos (por módulo y acción) que tendrá este rol.'),

                // Criterio 2: El método getShieldFormComponents() agregado al formulario
                static::getShieldFormComponents(),
            ]);
    }
}