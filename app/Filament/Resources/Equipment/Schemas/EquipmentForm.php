<?php

namespace App\Filament\Resources\Equipment\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EquipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('patrimonial_code')
                    ->label('Código Patrimonial UCH')
                    ->placeholder('Ej: UCH-EQ-00123')
                    ->helperText('Código único institucional asignado al equipo.')
                    ->required()
                    ->minLength(3)
                    ->maxLength(50)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'required' => 'El código patrimonial es obligatorio.',
                        'unique'   => 'Este código patrimonial ya se encuentra registrado en el sistema.',
                        'min'      => 'El código patrimonial debe tener al menos 3 caracteres.',
                        'max'      => 'El código patrimonial no puede superar los 50 caracteres.',
                    ]),

                Select::make('type')
                    ->label('Tipo / Categoría de Equipo')
                    ->placeholder('Seleccione una categoría')
                    ->helperText('Seleccione el tipo de recurso que ingresa al inventario.')
                    ->options([
                        'Computadora' => 'Computadora de Escritorio',
                        'Laptop'      => 'Laptop / Portátil',
                        'Periférico'  => 'Periférico (Mouse, Teclado, Monitor, etc.)',
                        'Otro'        => 'Otro Recurso Tecnológico',
                    ])
                    ->required()
                    ->validationMessages([
                        'required' => 'Debe seleccionar el tipo o categoría de equipo.',
                    ]),

                TextInput::make('brand')
                    ->label('Marca')
                    ->placeholder('Ej: HP, Dell, Lenovo, Logitech')
                    ->helperText('Marca del fabricante del recurso.')
                    ->required()
                    ->minLength(2)
                    ->maxLength(100)
                    ->validationMessages([
                        'required' => 'La marca del equipo es obligatoria.',
                        'min'      => 'La marca debe tener al menos 2 caracteres.',
                        'max'      => 'La marca no puede superar los 100 caracteres.',
                    ]),

                TextInput::make('model')
                    ->label('Modelo')
                    ->placeholder('Ej: ThinkPad T14, OptiPlex 7090')
                    ->helperText('Modelo comercial o serie específica del equipo.')
                    ->required()
                    ->minLength(2)
                    ->maxLength(100)
                    ->validationMessages([
                        'required' => 'El modelo del equipo es obligatorio.',
                        'min'      => 'El modelo debe tener al menos 2 caracteres.',
                        'max'      => 'El modelo no puede superar los 100 caracteres.',
                    ]),

                TextInput::make('serial_number')
                    ->label('Número de Serie')
                    ->placeholder('Ej: SN-9876543210')
                    ->helperText('Número de serie único de fábrica.')
                    ->required()
                    ->minLength(3)
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'required' => 'El número de serie es obligatorio.',
                        'unique'   => 'Este número de serie ya está registrado en el inventario.',
                        'min'      => 'El número de serie debe tener al menos 3 caracteres.',
                        'max'      => 'El número de serie no puede superar los 100 caracteres.',
                    ]),

                Select::make('status')
                    ->label('Estado Operativo')
                    ->options([
                        'Disponible'    => 'Disponible',
                        'Prestado'      => 'Prestado',
                        'Mantenimiento' => 'Mantenimiento',
                        'Baja'          => 'Baja',
                    ])
                    ->default('Disponible')
                    ->required()
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->helperText('Estado operativo del equipo en el catálogo.'),

                Textarea::make('observations')
                    ->label('Observaciones')
                    ->placeholder('Ingrese observaciones técnicas, estado estético o accesorios incluidos.')
                    ->helperText('Notas u observaciones opcionales sobre el estado o componentes del equipo.')
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }
}
