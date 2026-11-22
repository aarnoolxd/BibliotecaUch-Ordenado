<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre Completo')
                    ->placeholder('Ej: Juan Pérez')
                    ->helperText('Ingrese el nombre y apellidos completos del usuario.')
                    ->required()
                    ->minLength(3)
                    ->maxLength(255)
                    ->live()
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'required' => 'El nombre es obligatorio.',
                        'min' => 'El nombre debe tener al menos 3 caracteres.',
                        'max' => 'El nombre no puede superar los 255 caracteres.',
                        'unique' => 'Este nombre ya se encuentra registrado.',
                    ]),

                TextInput::make('email')
                    ->label('Correo Electrónico')
                    ->placeholder('Ej: usuario@uch.pe')
                    ->helperText('Ingrese la dirección de correo electrónico institucional o personal.')
                    ->email()
                    ->required()
                    ->minLength(5)
                    ->maxLength(255)
                    ->live()
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'required' => 'El correo electrónico es obligatorio.',
                        'email' => 'Debe ingresar un formato de correo electrónico válido.',
                        'min' => 'El correo electrónico debe tener al menos 5 caracteres.',
                        'max' => 'El correo electrónico no puede superar los 255 caracteres.',
                        'unique' => 'Este correo electrónico ya se encuentra registrado.',
                    ]),

                TextInput::make('password')
                    ->label('Contraseña')
                    ->placeholder('••••••••')
                    ->helperText('Mínimo 8 caracteres, incluyendo mayúsculas, minúsculas, números y caracteres especiales.')
                    ->password()
                    ->revealable()
                    ->required(fn(string $operation): bool => $operation === 'create')
                    ->dehydrated(fn(?string $state): bool => filled($state))
                    ->minLength(8)
                    ->maxLength(255)
                    ->rule(Password::min(8)->letters()->mixedCase()->numbers()->symbols())
                    ->validationMessages([
                        'required' => 'La contraseña es obligatoria.',
                        'min' => 'La contraseña debe tener al menos 8 caracteres.',
                        'max' => 'La contraseña no puede superar los 255 caracteres.',
                    ]),

                Select::make('roles')
                    ->label('Roles de Usuario')
                    ->placeholder('Seleccione los roles')
                    ->helperText('Seleccione uno o más roles asignados a esta cuenta de usuario.')
                    ->relationship('roles', 'name')
                    ->preload()
                    ->searchable(),
            ]);
    }
}
