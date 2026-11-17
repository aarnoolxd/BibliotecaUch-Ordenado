<?php

namespace App\Filament\Resources\Profiles\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('dni')
                    ->label('DNI')
                    ->placeholder('Ej: 71234567')
                    ->helperText('Documento Nacional de Identidad de 8 dígitos numéricos.')
                    ->required()
                    ->minLength(8)
                    ->maxLength(8)
                    ->regex('/^[0-9]{8}$/')
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'required' => 'El número de DNI es obligatorio.',
                        'regex'    => 'El DNI debe contener exactamente 8 dígitos numéricos.',
                        'min'      => 'El DNI debe tener exactamente 8 dígitos.',
                        'max'      => 'El DNI no puede superar los 8 dígitos.',
                        'unique'   => 'Este número de DNI ya se encuentra registrado.',
                    ]),

                TextInput::make('first_name')
                    ->label('Nombres')
                    ->placeholder('Ej: Juan Alberto')
                    ->helperText('Ingrese los nombres de la persona.')
                    ->required()
                    ->minLength(2)
                    ->maxLength(255)
                    ->validationMessages([
                        'required' => 'Los nombres son obligatorios.',
                        'min'      => 'Los nombres deben tener al menos 2 caracteres.',
                        'max'      => 'Los nombres no pueden superar los 255 caracteres.',
                    ]),

                TextInput::make('last_name')
                    ->label('Apellidos')
                    ->placeholder('Ej: Sedano Quispe')
                    ->helperText('Ingrese los apellidos completos de la persona.')
                    ->required()
                    ->minLength(2)
                    ->maxLength(255)
                    ->validationMessages([
                        'required' => 'Los apellidos son obligatorios.',
                        'min'      => 'Los apellidos deben tener al menos 2 caracteres.',
                        'max'      => 'Los apellidos no pueden superar los 255 caracteres.',
                    ]),

                TextInput::make('phone')
                    ->label('Teléfono / Celular')
                    ->placeholder('Ej: 987654321')
                    ->helperText('Número telefónico de contacto.')
                    ->tel()
                    ->maxLength(20)
                    ->validationMessages([
                        'max' => 'El número telefónico no puede superar los 20 caracteres.',
                    ]),

                TextInput::make('address')
                    ->label('Dirección')
                    ->placeholder('Ej: Av. Universitaria 1234, Los Olivos')
                    ->helperText('Dirección de domicilio o residencia.')
                    ->maxLength(255)
                    ->validationMessages([
                        'max' => 'La dirección no puede superar los 255 caracteres.',
                    ]),
            ]);
    }
}
