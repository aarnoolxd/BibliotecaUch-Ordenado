<?php

namespace App\Filament\Resources\Borrowings\Schemas;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Profile;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BorrowingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Subtarea [UB-145] / Criterio 2: Validar existencia del DNI en el sistema
                TextInput::make('student_dni')
                    ->label('DNI del Estudiante')
                    ->placeholder('Ingrese DNI de 8 dígitos')
                    ->helperText('Debe corresponder al DNI de un estudiante o perfil registrado en el sistema.')
                    ->required()
                    ->length(8)
                    ->regex('/^[0-9]{8}$/')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set): void {
                        if (blank($state)) {
                            $set('profile_id', null);
                            $set('student_name', null);

                            return;
                        }

                        $profile = Profile::where('dni', trim($state))->first();
                        if ($profile) {
                            $set('profile_id', $profile->id);
                            $set('student_name', $profile->full_name);
                        } else {
                            $set('profile_id', null);
                            $set('student_name', null);
                        }
                    })
                    ->rules([
                        fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                            $exists = Profile::where('dni', trim($value))->exists();
                            if (! $exists) {
                                $fail('El DNI ingresado no se encuentra registrado en el sistema. Debe registrar previamente el perfil del estudiante.');
                            }
                        },
                    ])
                    ->validationMessages([
                        'required' => 'El DNI del estudiante es obligatorio.',
                        'length' => 'El DNI debe contener exactamente 8 dígitos numéricos.',
                        'regex' => 'El DNI solo debe contener números.',
                    ]),

                TextInput::make('student_name')
                    ->label('Estudiante Identificado')
                    ->disabled()
                    ->dehydrated(false)
                    ->afterStateHydrated(function (TextInput $component, ?Borrowing $record): void {
                        if ($record?->profile !== null) {
                            $component->state($record->profile->full_name);
                        }
                    })
                    ->placeholder('Se completará automáticamente al ingresar un DNI válido')
                    ->helperText('Nombre del estudiante obtenido a partir del DNI ingresado.'),

                TextInput::make('profile_id')
                    ->hidden()
                    ->dehydrated(),

                // Subtarea [UB-142] / Criterio 3: Verificar que el recurso seleccionado esté en estado "Disponible"
                Select::make('equipment_id')
                    ->label('Equipo / Recurso a prestar')
                    ->placeholder('Seleccione un recurso disponible')
                    ->helperText('Solo se permite seleccionar equipos con estado operativo "Disponible".')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->options(function () {
                        return Equipment::all()->mapWithKeys(function ($item) {
                            $label = "[{$item->patrimonial_code}] {$item->brand} {$item->model} ({$item->type}) - Estado: {$item->status}";

                            return [$item->id => $label];
                        });
                    })
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                        if (! $state) {
                            return;
                        }

                        $equipment = Equipment::find($state);
                        if (! $equipment) {
                            return;
                        }

                        // Criterio 4 / Subtarea [UB-141]: Calcular automáticamente la fecha límite de devolución según tipo de recurso
                        $borrowingDateString = $get('borrowing_date') ?: now()->toDateTimeString();
                        $baseDate = Carbon::parse($borrowingDateString);
                        $calculatedDueDate = Borrowing::calculateDueDate($equipment->type, $baseDate);

                        $set('due_date', $calculatedDueDate->format('Y-m-d H:i:s'));
                    })
                    ->rules([
                        fn (?Borrowing $record): Closure => function (string $attribute, $value, Closure $fail) use ($record): void {
                            $equipment = Equipment::find($value);
                            if (! $equipment) {
                                $fail('El recurso seleccionado no existe.');

                                return;
                            }

                            if ($record !== null && (int) $record->equipment_id === (int) $equipment->getKey()) {
                                return;
                            }

                            if ($equipment->status !== 'Disponible') {
                                $fail("El recurso '{$equipment->brand} {$equipment->model}' no se encuentra disponible (Estado actual: {$equipment->status}). No se puede registrar el préstamo.");
                            }
                        },
                    ])
                    ->validationMessages([
                        'required' => 'Debe seleccionar un equipo para registrar el préstamo.',
                    ]),

                // Subtarea [UB-141] / Criterio 4: Registrar fecha y hora del préstamo junto con fecha límite
                DateTimePicker::make('borrowing_date')
                    ->label('Fecha y Hora del Préstamo')
                    ->default(fn () => now())
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, callable $get): void {
                        $equipmentId = $get('equipment_id');
                        if (! $equipmentId || ! $state) {
                            return;
                        }

                        $equipment = Equipment::find($equipmentId);
                        if (! $equipment) {
                            return;
                        }

                        $baseDate = Carbon::parse($state);
                        $calculatedDueDate = Borrowing::calculateDueDate($equipment->type, $baseDate);

                        $set('due_date', $calculatedDueDate->format('Y-m-d H:i:s'));
                    })
                    ->helperText('Momento en que se entrega el recurso al estudiante.'),

                DateTimePicker::make('due_date')
                    ->label('Fecha Límite de Devolución')
                    ->required()
                    ->afterOrEqual('borrowing_date')
                    ->native(false)
                    ->readOnly(fn (string $operation): bool => $operation === 'create')
                    ->validationMessages([
                        'after_or_equal' => 'La fecha límite de devolución no puede ser anterior a la fecha del préstamo.',
                    ])
                    ->helperText('Calculada automáticamente según el tipo de recurso seleccionado.'),

                TextInput::make('status')
                    ->label('Estado del Préstamo')
                    ->default('En curso')
                    ->disabled()
                    ->dehydrated()
                    ->helperText('Se asigna automáticamente como "En curso" al momento de guardar.'),

                Textarea::make('observations')
                    ->label('Observaciones del Préstamo')
                    ->placeholder('Condiciones de entrega, accesorios incluidos o notas adicionales...')
                    ->helperText('Detalles u observaciones opcionales del préstamo.')
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }
}
