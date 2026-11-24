<?php

namespace App\Filament\Resources\Borrowings\Tables;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use App\Services\BorrowingReturnService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class BorrowingTable
{
    public static function configure(Table $table): Table
    {
        /** @var list<string> $storedPhotoPaths */
        $storedPhotoPaths = [];

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
                        $term = '%'.mb_strtolower(trim($search)).'%';

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
                    ->default(fn (Borrowing $record): string => $record->profile?->full_name ?? 'Estudiante no vinculado')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('equipment.patrimonial_code')
                    ->label('Código de Equipo')
                    ->placeholder('Sin equipo principal')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('equipment.model')
                    ->label('Equipo Prestado')
                    ->placeholder('Sin equipo principal')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('additional_equipment_labels')
                    ->label('Periféricos / Otros')
                    ->state(fn (Borrowing $record): array => $record->additionalEquipment
                        ->map(fn (Equipment $item): string => "{$item->model}")
                        ->all())
                    ->badge()
                    ->color('gray')
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('Sin periféricos')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = '%'.mb_strtolower(trim($search)).'%';

                        return $query->whereHas('additionalEquipment', function (Builder $equipmentQuery) use ($term): void {
                            $equipmentQuery->whereRaw(
                                "LOWER(COALESCE(patrimonial_code, '') || ' ' || COALESCE(brand, '') || ' ' || COALESCE(model, '')) LIKE ?",
                                [$term],
                            );
                        });
                    })
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
                    ->color(fn (string $state): string|array => Borrowing::statusColor($state)),

                TextColumn::make('observations')
                    ->label('Observaciones')
                    ->wrap()
                    ->placeholder('Sin observaciones')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['equipment', 'additionalEquipment', 'profile', 'registeredBy', 'incidents.equipment']))
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado del préstamo')
                    ->placeholder('Todos los estados')
                    ->options([
                        Borrowing::STATUS_IN_PROGRESS => Borrowing::STATUS_IN_PROGRESS,
                        Borrowing::STATUS_RETURNED => Borrowing::STATUS_RETURNED,
                        Borrowing::STATUS_OVERDUE => Borrowing::STATUS_OVERDUE,
                    ])
                    ->native(false),

                TernaryFilter::make('has_incidents')
                    ->label('Incidencias por daño')
                    ->placeholder('Todos los préstamos')
                    ->trueLabel('Con incidencias')
                    ->falseLabel('Sin incidencias')
                    ->native(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('incidents'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('incidents'),
                    ),
            ], layout: FiltersLayout::Modal)
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->slideOver()
                    ->modalCancelAction(false),
            )
            ->recordActions([
                ActionGroup::make([
                    Action::make('registerReturn')
                        ->label('Devolución')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('success')
                        ->visible(fn (Borrowing $record): bool => in_array($record->status, Borrowing::ACTIVE_STATUSES, true))
                        ->authorize('registerReturn')
                        ->modalHeading('Registrar devolución')
                        ->modalDescription('Indica si algún equipo presenta daños.')
                        ->modalWidth(Width::ThreeExtraLarge)
                        ->modalSubmitActionLabel('Confirmar')
                        ->modalCancelActionLabel('Cancelar')
                        ->fillForm(fn (Borrowing $record): array => [
                            'items' => self::buildReturnItems($record),
                        ])
                        ->schema([
                            Repeater::make('items')
                                ->label('Dispositivos incluidos en el préstamo')
                                ->addable(false)
                                ->deletable(false)
                                ->reorderable(false)
                                ->itemLabel(fn (array $state): ?string => $state['equipment_label'] ?? null)
                                ->schema([
                                    Hidden::make('equipment_id')
                                        ->required(),
                                    Hidden::make('equipment_label'),
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
                                        ->visible(fn (Get $get): bool => (bool) $get('has_damage'))
                                        ->required(fn (Get $get): bool => (bool) $get('has_damage'))
                                        ->validationMessages([
                                            'required' => 'Debe describir el daño detectado en el recurso.',
                                            'max' => 'El detalle del daño no puede superar los 500 caracteres.',
                                        ]),
                                    FileUpload::make('photo')
                                        ->label('Fotografía de evidencia')
                                        ->helperText('Adjunte una fotografía en formato JPG o PNG (máx. 4 MB) que evidencie el daño.')
                                        ->disk(fn (): string => EquipmentIncident::photoDisk())
                                        ->visibility('private')
                                        ->image()
                                        ->acceptedFileTypes(['image/jpeg', 'image/png'])
                                        ->maxSize(4096)
                                        ->directory('borrowings/incidents')
                                        ->preventFilePathTampering()
                                        ->saveUploadedFileUsing(function (FileUpload $component, TemporaryUploadedFile $file) use (&$storedPhotoPaths): ?string {
                                            try {
                                                $path = $component->saveUploadedFile($file);
                                            } catch (Throwable $exception) {
                                                self::cleanupUnreferencedPhotos($storedPhotoPaths);
                                                $storedPhotoPaths = [];

                                                throw $exception;
                                            }

                                            if (is_string($path) && trim($path) !== '') {
                                                $storedPhotoPaths[] = $path;
                                            }

                                            return $path;
                                        })
                                        ->visible(fn (Get $get): bool => (bool) $get('has_damage'))
                                        ->required(fn (Get $get): bool => (bool) $get('has_damage'))
                                        ->validationMessages([
                                            'required' => 'Debe adjuntar una fotografía de evidencia del daño.',
                                            'image' => 'El archivo debe ser una imagen válida.',
                                            'max' => 'La fotografía no debe superar los 4 MB (4096 KB).',
                                            'mimes' => 'La fotografía debe ser un archivo de formato JPG o PNG.',
                                            'mimetypes' => 'La fotografía debe ser un archivo de formato JPG o PNG.',
                                        ]),
                                ]),
                        ])
                        ->action(function (Borrowing $record, array $data) use (&$storedPhotoPaths): void {
                            $uploadedPhotoPaths = array_values(array_unique(array_merge(
                                $storedPhotoPaths,
                                self::extractUploadedPhotoPaths($data['items'] ?? []),
                            )));
                            $storedPhotoPaths = [];

                            try {
                                $result = app(BorrowingReturnService::class)->registerReturn(
                                    borrowing: $record,
                                    evaluations: $data['items'] ?? [],
                                );
                            } catch (ValidationException $exception) {
                                self::cleanupUnreferencedPhotos($uploadedPhotoPaths);

                                $errors = $exception->errors();
                                $hasEquipmentMismatch = array_key_exists('borrowing', $errors)
                                    || collect(array_keys($errors))->contains(
                                        fn (string $key): bool => Str::is('items.*.equipment_id', $key),
                                    );

                                $body = match (true) {
                                    array_key_exists('borrowing', $errors) => (string) ($errors['borrowing'][0] ?? 'Los equipos cambiaron. Reabre la ficha.'),
                                    $hasEquipmentMismatch => 'Los equipos cambiaron. Reabre la ficha e intenta otra vez.',
                                    default => (string) (collect($errors)->flatten()->first() ?? 'Revisa los datos e intenta otra vez.'),
                                };

                                Notification::make()
                                    ->title('No se pudo devolver')
                                    ->body($body)
                                    ->danger()
                                    ->persistent()
                                    ->send();

                                return;
                            } catch (Throwable $exception) {
                                self::cleanupUnreferencedPhotos($uploadedPhotoPaths);

                                throw $exception;
                            }

                            self::cleanupUnreferencedPhotos($uploadedPhotoPaths);

                            $evaluatedCount = count($data['items'] ?? []);

                            $body = match (true) {
                                $result['maintenance_count'] > 0 && $result['released_count'] > 0 => "{$result['released_count']} disponible(s) y {$result['maintenance_count']} a mantenimiento.",
                                $result['maintenance_count'] > 0 => "{$result['maintenance_count']} equipo(s) pasaron a mantenimiento.",
                                $result['released_count'] > 0 && $result['released_count'] === $evaluatedCount => 'Todos los equipos volvieron a Disponible.',
                                $result['released_count'] > 0 => "{$result['released_count']} equipo(s) volvieron a Disponible.",
                                default => 'Estado actualizado.',
                            };

                            Notification::make()
                                ->title('Devolución registrada')
                                ->body($body)
                                ->success()
                                ->send();
                        }),
                    BorrowingResource::borrowingDetailsAction(),
                    EditAction::make()
                        ->visible(fn (Borrowing $record): bool => $record->status === Borrowing::STATUS_IN_PROGRESS),
                ])
                    ->label('Acciones')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->size(Size::Small)
                    ->color('gray')
                    ->button(),
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
                'equipment_id' => (int) $record->equipment->getKey(),
                'equipment_label' => "[{$record->equipment->patrimonial_code}] {$record->equipment->brand} {$record->equipment->model}",
                'has_damage' => false,
                'description' => null,
                'photo' => null,
            ];
        }

        foreach ($record->additionalEquipment as $additional) {
            $items[] = [
                'equipment_id' => (int) $additional->getKey(),
                'equipment_label' => "[{$additional->patrimonial_code}] {$additional->brand} {$additional->model}",
                'has_damage' => false,
                'description' => null,
                'photo' => null,
            ];
        }

        return $items;
    }

    /**
     * Extrae las rutas de fotografías subidas en el formulario de devolución.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return list<string>
     */
    private static function extractUploadedPhotoPaths(array $items): array
    {
        $paths = [];

        foreach ($items as $item) {
            foreach (Arr::flatten(Arr::wrap($item['photo'] ?? [])) as $photo) {
                if (is_string($photo) && trim($photo) !== '') {
                    $paths[] = $photo;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Elimina fotografías recién cargadas solo cuando se confirma en base de datos
     * que no pertenecen a ninguna incidencia persistida.
     *
     * @param  list<string>  $paths
     */
    private static function cleanupUnreferencedPhotos(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        try {
            $referencedPaths = EquipmentIncident::query()
                ->whereIn('photo', $paths)
                ->pluck('photo')
                ->all();
        } catch (Throwable) {
            return;
        }

        $orphanPaths = array_values(array_diff($paths, $referencedPaths));

        if ($orphanPaths === []) {
            return;
        }

        Storage::disk(EquipmentIncident::photoDisk())->delete($orphanPaths);
    }
}
