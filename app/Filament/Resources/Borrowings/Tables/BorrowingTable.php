<?php

namespace App\Filament\Resources\Borrowings\Tables;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Services\BorrowingReturnService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BorrowingTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('student_dni')
                    ->label('DNI Estudiante')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('DNI copiado al portapapeles'),

                TextColumn::make('profile.full_name')
                    ->label('Estudiante')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = '%' . mb_strtolower(trim($search)) . '%';

                        return $query->whereHas('profile', function (Builder $profileQuery) use ($term): void {
                            $profileQuery->whereRaw(
                                "LOWER(TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '') || ' ' || COALESCE(maternal_last_name, ''))) LIKE ?",
                                [$term],
                            );
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->leftJoin('profiles', 'profiles.id', '=', 'borrowings.profile_id')
                            ->orderBy('profiles.first_name', $direction)
                            ->orderBy('profiles.last_name', $direction)
                            ->orderBy('profiles.maternal_last_name', $direction)
                            ->select('borrowings.*');
                    })
                    ->default(fn(Borrowing $record): string => $record->profile?->full_name ?? 'Estudiante no vinculado')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('equipment.patrimonial_code')
                    ->label('Cód. Patrimonial')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('equipment.model')
                    ->label('Equipo Prestado')
                    ->placeholder('Sin equipo principal')
                    ->description(fn(Borrowing $record): ?string => $record->equipment ? "{$record->equipment->type} - {$record->equipment->brand}" : null)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('additional_equipment_labels')
                    ->label('Periféricos / Otros')
                    ->state(fn(Borrowing $record): array => $record->additionalEquipment
                        ->map(fn(Equipment $item): string => "{$item->model}")
                        ->all())
                    ->badge()
                    ->color('gray')
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('borrowing_date')
                    ->label('Fecha Préstamo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Límite Devolución')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Sin límite')
                    ->sortable(),

                TextColumn::make('return_date')
                    ->label('Fecha Devolución')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        Borrowing::STATUS_IN_PROGRESS => 'info',
                        Borrowing::STATUS_RETURNED => 'success',
                        Borrowing::STATUS_OVERDUE => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('observations')
                    ->label('Observaciones')
                    ->wrap()
                    ->placeholder('Sin observaciones')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn(Builder $query): Builder => $query->with(['equipment', 'additionalEquipment', 'profile']))
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado del préstamo')
                    ->placeholder('Todos los estados')
                    ->options([
                        Borrowing::STATUS_IN_PROGRESS => Borrowing::STATUS_IN_PROGRESS,
                        Borrowing::STATUS_RETURNED => Borrowing::STATUS_RETURNED,
                        Borrowing::STATUS_OVERDUE => Borrowing::STATUS_OVERDUE,
                    ]),
            ])
            ->recordActions([
                Action::make('registerReturn')
                    ->label('Registrar Devolución')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('success')
                    ->visible(fn(Borrowing $record): bool => in_array($record->status, Borrowing::ACTIVE_STATUSES, true))
                    ->authorize('update')
                    ->modalHeading('Registrar devolución del préstamo')
                    ->modalDescription('Indique si alguno de los dispositivos entregados presenta daños. Los equipos sin daños volverán a estar disponibles automáticamente.')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalSubmitActionLabel('Confirmar Devolución')
                    ->fillForm(fn(Borrowing $record): array => [
                        'items' => self::buildReturnItems($record),
                    ])
                    ->schema([
                        Repeater::make('items')
                            ->label('Dispositivos incluidos en el préstamo')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->itemLabel(fn(array $state): ?string => $state['equipment_label'] ?? null)
                            ->schema([
                                Hidden::make('equipment_id')
                                    ->required(),
                                Hidden::make('equipment_label')
                                    ->dehydrated(false),
                                Toggle::make('has_damage')
                                    ->label('¿Presenta daños?')
                                    ->helperText('Active esta opción únicamente si el dispositivo presenta daños o fallas al ser devuelto.')
                                    ->default(false)
                                    ->live(),
                                Textarea::make('description')
                                    ->label('Detalle del daño')
                                    ->placeholder('Describa el daño o falla detectada al recibir el dispositivo...')
                                    ->rows(2)
                                    ->maxLength(500)
                                    ->visible(fn(Get $get): bool => (bool)$get('has_damage'))
                                    ->required(fn(Get $get): bool => (bool)$get('has_damage'))
                                    ->validationMessages([
                                        'required' => 'Debe describir el daño detectado en el recurso.',
                                        'max' => 'El detalle del daño no puede superar los 500 caracteres.',
                                    ]),
                                FileUpload::make('photo')
                                    ->label('Fotografía de evidencia')
                                    ->helperText('Adjunte una fotografía en formato JPG o PNG (máx. 4 MB) que evidencie el daño.')
                                    ->image()
                                    ->acceptedFileTypes(['image/jpeg', 'image/png'])
                                    ->maxSize(4096)
                                    ->directory('borrowings/incidents')
                                    ->visible(fn(Get $get): bool => (bool)$get('has_damage'))
                                    ->required(fn(Get $get): bool => (bool)$get('has_damage'))
                                    ->validationMessages([
                                        'required' => 'Debe adjuntar una fotografía de evidencia del daño.',
                                        'image' => 'El archivo debe ser una imagen válida.',
                                        'max' => 'La fotografía no debe superar los 4 MB (4096 KB).',
                                        'mimes' => 'La fotografía debe ser un archivo de formato JPG o PNG.',
                                        'mimetypes' => 'La fotografía debe ser un archivo de formato JPG o PNG.',
                                    ]),
                            ]),
                    ])
                    ->action(function (Borrowing $record, array $data): void {
                        $result = app(BorrowingReturnService::class)->registerReturn(
                            borrowing: $record,
                            evaluations: $data['items'] ?? [],
                        );

                        $body = match (true) {
                            $result['maintenance_count'] > 0 && $result['released_count'] > 0 => "Devolución registrada. {$result['released_count']} recurso(s) volvieron a Disponible y {$result['maintenance_count']} pasaron a Mantenimiento por daños reportados.",
                            $result['maintenance_count'] > 0 => "Devolución registrada. {$result['maintenance_count']} recurso(s) pasaron a Mantenimiento debido a los daños reportados.",
                            default => 'Devolución procesada con éxito. Todos los recursos volvieron a estado Disponible.',
                        };

                        Notification::make()
                            ->title('Devolución registrada')
                            ->body($body)
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                // All actions are defined
            ]);
    }

    /**
     * Construye los elementos iniciales del modal de devolución (equipo principal + adicionales).
     *
     * @return list<array{equipment_id: int, equipment_label: string, has_damage: bool, description: null, photo: null}>
     */
    private static function buildReturnItems(Borrowing $record): array
    {
        $record->loadMissing(['equipment', 'additionalEquipment']);

        $items = [];

        if ($record->equipment !== null) {
            $items[] = [
                'equipment_id' => (int)$record->equipment->getKey(),
                'equipment_label' => "Equipo principal — [{$record->equipment->patrimonial_code}] {$record->equipment->brand} {$record->equipment->model} ({$record->equipment->type})",
                'has_damage' => false,
                'description' => null,
                'photo' => null,
            ];
        }

        foreach ($record->additionalEquipment as $additional) {
            $items[] = [
                'equipment_id' => (int)$additional->getKey(),
                'equipment_label' => "Adicional — [{$additional->patrimonial_code}] {$additional->brand} {$additional->model} ({$additional->type})",
                'has_damage' => false,
                'description' => null,
                'photo' => null,
            ];
        }

        return $items;
    }
}
