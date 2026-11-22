<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Models\Role;
use BezhanSalleh\FilamentShield\Traits\HasShieldFormComponents;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

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
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule, Get $get, ?Role $record): Unique {
                            return $rule->where('guard_name', $record?->guard_name ?? $get('guard_name'));
                        }
                    )
                    ->minLength(3)
                    ->maxLength(64)
                    ->live()
                    ->disabled(fn (?Role $record): bool => $record !== null && in_array($record->name, Role::PANEL_ROLES, true))
                    ->validationMessages([
                        'required' => 'El nombre del rol es obligatorio.',
                        'unique' => 'Este nombre de rol ya está registrado.',
                        'min' => 'El nombre debe tener al menos 3 caracteres.',
                        'max' => 'El nombre no puede superar los 64 caracteres.',
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
                    ->disabledOn('edit')
                    ->validationMessages([
                        'required' => 'El grupo de seguridad es obligatorio.',
                        'min' => 'El guard debe tener al menos 3 caracteres.',
                        'max' => 'El guard no puede superar los 32 caracteres.',
                    ]),

                // Criterio 4: Texto informativo que identifica para qué sirve la matriz de accesos
                TextEntry::make('permissions_matrix_info')
                    ->label('Matriz de Accesos')
                    ->state('Seleccione a continuación los permisos específicos (por módulo y acción) que tendrá este rol.'),

                // Criterio 2: El método getShieldFormComponents() agregado al formulario
                static::getShieldFormComponents(),
            ]);
    }
}
