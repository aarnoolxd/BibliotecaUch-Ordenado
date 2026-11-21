<?php

namespace App\Filament\Resources\Permissions\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class PermissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre del Permiso')
                    ->helperText('Ingrese el identificador técnico del permiso.')
                    ->placeholder('Ejemplo: gestionar maquinas')
                    ->required()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: function (Unique $rule, Get $get) {
                            return $rule->where('guard_name', $get('guard_name'));
                        }
                    )
                    ->validationMessages([
                        'unique' => 'Este nombre de permiso ya se encuentra registrado en el sistema.',
                    ])
                    ->live(onBlur: true)
                    ->maxLength(255),

                TextInput::make('guard_name')
                    ->label('Grupo de Autenticación')
                    ->helperText('Indique el grupo de autenticación asociado.')
                    ->placeholder('web')
                    ->default('web')
                    ->minLength(3)
                    ->maxLength(32)
                    ->live()
                    ->required()
                    ->validationMessages([
                        'required' => 'El campo :attribute es obligatorio.',
                        'min' => 'El campo :attribute debe tener al menos :min caracteres.',
                        'max' => 'El campo :attribute no debe exceder :max caracteres.',
                    ]),

                Textarea::make('description')
                    ->label('Descripción')
                    ->helperText('Proporcione una breve descripción sobre el permiso.')
                    ->placeholder('Permite registrar y listar elementos')
                    ->rows(3)
                    ->columnSpanFull()
                    ->live()
                    ->minLength(8)
                    ->maxLength(255)
                    ->validationMessages([
                        'min' => 'El campo :attribute debe tener al menos :min caracteres.',
                        'max' => 'El campo :attribute no debe exceder :max caracteres.',
                    ]),
            ]);
    }
}
