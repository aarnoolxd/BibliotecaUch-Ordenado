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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class BorrowingForm
{
    /**
     * Determina si el equipo principal seleccionado corresponde a una Computadora
     * y por lo tanto requiere fecha límite de devolución (+2 horas).
     */
    public static function requiresDueDate(mixed $equipmentId): bool
    {
        if (blank($equipmentId)) {
            return false;
        }

        return Equipment::query()
            ->whereKey((int) $equipmentId)
            ->where('type', 'Computadora')
            ->exists();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('student_dni')
                    ->label('DNI del Estudiante')
                    ->placeholder('Ingrese DNI de 8 dígitos')
                    ->helperText('Debe corresponder al DNI de un estudiante o perfil registrado en el sistema.')
                    ->required()
                    ->length(8)
                    ->regex('/^[0-9]{8}$/')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if (blank($state)) {
                            $set('profile_id', null);
                            $set('student_name', null);

                            return;
                        }

                        $profile = Profile::query()->where('dni', trim($state))->first();

                        if ($profile !== null) {
                            $set('profile_id', $profile->id);
                            $set('student_name', $profile->full_name);
                        } else {
                            $set('profile_id', null);
                            $set('student_name', null);
                        }
                    })
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $exists = Profile::query()->where('dni', trim((string) $value))->exists();

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

                Select::make('equipment_id')
                    ->label('Equipo Principal (PC o Laptop)')
                    ->placeholder('Ninguno / Seleccione una Computadora o Laptop disponible')
                    ->helperText('Opcional si solo prestará periféricos u otros dispositivos. Solo permite Computadoras y Laptops.')
                    ->nullable()
                    ->requiredWithout('additional_equipment_ids')
                    ->searchable()
                    ->preload()
                    ->disabled(fn (?Borrowing $record): bool => $record?->status === Borrowing::STATUS_RETURNED)
                    ->dehydrated()
                    ->options(function (): array {
                        return Equipment::query()
                            ->whereIn('type', Equipment::PRIMARY_BORROWING_TYPES)
                            ->orderBy('patrimonial_code')
                            ->get()
                            ->mapWithKeys(function (Equipment $item): array {
                                $label = "[{$item->patrimonial_code}] {$item->brand} {$item->model} ({$item->type}) - Estado: {$item->status}";

                                return [$item->id => $label];
                            })
                            ->all();
                    })
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set, Get $get): void {
                        if (blank($state)) {
                            $set('due_date', null);

                            return;
                        }

                        $equipment = Equipment::query()->find((int) $state);

                        if ($equipment === null) {
                            $set('due_date', null);

                            return;
                        }

                        $borrowingDateString = $get('borrowing_date') ?: now()->toDateTimeString();
                        $calculatedDueDate = Borrowing::calculateDueDate($equipment->type, Carbon::parse($borrowingDateString));

                        $set('due_date', $calculatedDueDate?->format('Y-m-d H:i:s'));
                    })
                    ->rules([
                        fn (?Borrowing $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (blank($value)) {
                                return;
                            }

                            $equipment = Equipment::query()->find((int) $value);

                            if ($equipment === null) {
                                $fail('El recurso seleccionado no existe.');

                                return;
                            }

                            if (! in_array($equipment->type, Equipment::PRIMARY_BORROWING_TYPES, true)) {
                                $fail('Solo puede seleccionar una Computadora o Laptop como equipo principal.');

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
                        'required_without' => 'Debe seleccionar al menos un equipo principal o un dispositivo adicional para registrar el préstamo.',
                    ]),

                Select::make('additional_equipment_ids')
                    ->label('Periféricos / Otros Dispositivos (Opcional)')
                    ->placeholder('Seleccione uno o más periféricos u otros dispositivos disponibles')
                    ->helperText('Opcional. Puede agregar varios periféricos u otros recursos junto a un equipo o prestarlos de forma independiente.')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->disabled(fn (?Borrowing $record): bool => $record?->status === Borrowing::STATUS_RETURNED)
                    ->dehydrated()
                    ->afterStateHydrated(function (Select $component, ?Borrowing $record): void {
                        if ($record !== null) {
                            $component->state(
                                $record->additionalEquipment()
                                    ->pluck('equipment.id')
                                    ->map(fn (mixed $id): int => (int) $id)
                                    ->all(),
                            );
                        }
                    })
                    ->options(function (): array {
                        return Equipment::query()
                            ->whereIn('type', Equipment::ADDITIONAL_BORROWING_TYPES)
                            ->orderBy('patrimonial_code')
                            ->get()
                            ->mapWithKeys(function (Equipment $item): array {
                                $label = "[{$item->patrimonial_code}] {$item->brand} {$item->model} ({$item->type}) - Estado: {$item->status}";

                                return [$item->id => $label];
                            })
                            ->all();
                    })
                    ->rules([
                        fn (?Borrowing $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if (blank($value) || ! is_array($value)) {
                                return;
                            }

                            $selectedIds = array_values(array_unique(array_map('intval', $value)));
                            $existingIds = $record !== null
                                ? $record->additionalEquipment()->pluck('equipment.id')->map(fn (mixed $id): int => (int) $id)->all()
                                : [];

                            $equipments = Equipment::query()
                                ->whereIn('id', $selectedIds)
                                ->get()
                                ->keyBy('id');

                            foreach ($selectedIds as $equipmentId) {
                                /** @var Equipment|null $item */
                                $item = $equipments->get($equipmentId);

                                if ($item === null) {
                                    $fail('Uno de los dispositivos adicionales seleccionados no existe en el catálogo.');

                                    return;
                                }

                                if (! in_array($item->type, Equipment::ADDITIONAL_BORROWING_TYPES, true)) {
                                    $fail("El recurso '{$item->brand} {$item->model}' no pertenece a la categoría Periférico u Otro.");

                                    return;
                                }

                                if (! in_array($equipmentId, $existingIds, true) && $item->status !== 'Disponible') {
                                    $fail("El dispositivo '{$item->brand} {$item->model}' no se encuentra disponible (Estado actual: {$item->status}).");

                                    return;
                                }
                            }
                        },
                    ]),

                DateTimePicker::make('borrowing_date')
                    ->label('Fecha y Hora del Préstamo')
                    ->default(fn () => now())
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set, Get $get): void {
                        $equipmentId = $get('equipment_id');

                        if (blank($equipmentId) || blank($state)) {
                            $set('due_date', null);

                            return;
                        }

                        $equipment = Equipment::query()->find((int) $equipmentId);

                        if ($equipment === null) {
                            $set('due_date', null);

                            return;
                        }

                        $calculatedDueDate = Borrowing::calculateDueDate($equipment->type, Carbon::parse($state));

                        $set('due_date', $calculatedDueDate?->format('Y-m-d H:i:s'));
                    })
                    ->helperText('Momento en que se entregan los recursos al estudiante.'),

                DateTimePicker::make('due_date')
                    ->label('Fecha Límite de Devolución')
                    ->visible(fn (Get $get): bool => self::requiresDueDate($get('equipment_id')))
                    ->required(fn (Get $get): bool => self::requiresDueDate($get('equipment_id')))
                    ->afterOrEqual('borrowing_date')
                    ->native(false)
                    ->readOnly(fn (string $operation): bool => $operation === 'create')
                    ->validationMessages([
                        'required' => 'La fecha límite de devolución es obligatoria para préstamos de Computadora.',
                        'after_or_equal' => 'La fecha límite de devolución no puede ser anterior a la fecha del préstamo.',
                    ])
                    ->helperText('Solo aplica para Computadoras (2 horas desde el préstamo). Laptops y periféricos no tienen límite de tiempo.'),

                TextInput::make('status')
                    ->label('Estado del Préstamo')
                    ->default(Borrowing::STATUS_IN_PROGRESS)
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
