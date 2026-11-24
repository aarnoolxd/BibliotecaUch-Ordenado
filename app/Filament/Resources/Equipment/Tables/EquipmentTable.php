<?php

namespace App\Filament\Resources\Equipment\Tables;

use App\Models\Equipment;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class EquipmentTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // UB-118: Paginación fluida – 15 registros por defecto con opciones ajustables
            ->defaultPaginationPageOption(15)
            ->paginationPageOptions([10, 15, 25, 50])

            // Orden cronológico por fecha más reciente (Criterio 4 HU-04)
            ->defaultSort('created_at', 'desc')

            // Indicador visual de carga diferida (Criterio 5 HU-04)
            ->deferLoading()

            // UB-117: Mensaje explicativo cuando el inventario esté vacío o sin coincidencias (Criterio 5 HU-04)
            ->emptyStateIcon(Heroicon::OutlinedComputerDesktop)
            ->emptyStateHeading(fn (Table $table): string => self::hasActiveSearchOrFilters($table)
                ? 'No se encontraron equipos'
                : 'Sin equipos registrados')
            ->emptyStateDescription(fn (Table $table): string => self::hasActiveSearchOrFilters($table)
                ? 'No hay equipos que coincidan con la búsqueda o los filtros aplicados.'
                : 'El inventario aún no tiene equipos registrados. Haz clic en "Crear Equipo" para añadir el primer recurso.')

            ->columns([
                TextColumn::make('patrimonial_code')
                    ->label('Cód. Patrimonial')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Categoría')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('brand')
                    ->label('Marca')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('model')
                    ->label('Modelo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Disponible' => 'success',
                        'Prestado' => 'warning',
                        'Mantenimiento' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('serial_number')
                    ->label('N° de Serie')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Número de serie copiado al portapapeles')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('observations')
                    ->label('Observaciones')
                    ->placeholder('Sin observaciones registradas.')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Fecha de Registro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Categoría')
                    ->placeholder('Todas las categorías')
                    ->options([
                        'Computadora' => 'Computadora',
                        'Laptop' => 'Laptop',
                        'Periférico' => 'Periférico',
                        'Otro' => 'Otro',
                    ])
                    ->native(false),

                SelectFilter::make('status')
                    ->label('Estado operativo')
                    ->placeholder('Todos los estados')
                    ->options([
                        'Disponible' => 'Disponible',
                        'Prestado' => 'Prestado',
                        'Mantenimiento' => 'Mantenimiento',
                        'Baja' => 'Baja',
                    ])
                    ->native(false),
            ], layout: FiltersLayout::Modal)
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->slideOver()
                    ->modalCancelAction(false),
            )
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('discontinue')
                    ->label('Descontinuar')
                    ->icon(Heroicon::OutlinedArchiveBoxXMark)
                    ->color('danger')
                    ->visible(fn (Equipment $record): bool => $record->status !== Equipment::STATUS_DISCONTINUED)
                    ->authorize('update')
                    ->modalHeading('Descontinuar equipo por baja técnica')
                    ->modalDescription('El equipo pasará al estado "Baja" y quedará inhabilitado para préstamos futuros.')
                    ->modalSubmitActionLabel('Confirmar Descontinuación')
                    ->schema([
                        Textarea::make('decommission_reason')
                            ->label('Motivo de la baja técnica')
                            ->placeholder('Detalle la falla irreparable, obsolescencia o motivo de retiro...')
                            ->helperText('Campo obligatorio para justificar el retiro técnico del recurso (5 a 500 caracteres).')
                            ->required()
                            ->trim()
                            ->minLength(5)
                            ->maxLength(500)
                            ->rows(3)
                            ->validationMessages([
                                'required' => 'Debe indicar el motivo de la baja técnica.',
                                'min' => 'El motivo de la baja técnica debe tener al menos 5 caracteres.',
                                'max' => 'El motivo de la baja técnica no puede superar los 500 caracteres.',
                            ]),
                    ])
                    ->action(function (Equipment $record, array $data): void {
                        $result = $record->discontinue((string) ($data['decommission_reason'] ?? ''));

                        match ($result) {
                            Equipment::DISCONTINUE_ACTIVE_BORROWING => Notification::make()
                                ->title('Operación no permitida')
                                ->body('No es posible descontinuar el equipo porque mantiene un préstamo activo. Primero debe registrarse su devolución.')
                                ->danger()
                                ->persistent()
                                ->send(),
                            Equipment::DISCONTINUE_ALREADY_DISCONTINUED => Notification::make()
                                ->title('El equipo ya se encuentra de baja')
                                ->body("El equipo {$record->patrimonial_code} ya había sido dado de baja previamente.")
                                ->warning()
                                ->send(),
                            Equipment::DISCONTINUE_SUCCESS => Notification::make()
                                ->title('Equipo descontinuado exitosamente')
                                ->body("El equipo {$record->patrimonial_code} pasó a estado Baja y quedó inhabilitado para préstamos futuros.")
                                ->success()
                                ->send(),
                            default => Notification::make()
                                ->title('Equipo no encontrado')
                                ->body('El equipo ya no se encuentra registrado en el inventario.')
                                ->danger()
                                ->send(),
                        };
                    }),
                DeleteAction::make()
                    ->before(function (DeleteAction $action, Equipment $record): void {
                        if ($record->hasActiveBorrowing()) {
                            Notification::make()
                                ->title('No se puede eliminar el equipo')
                                ->body('Este recurso está asociado a un préstamo activo. Registre primero su devolución.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records): void {
                            $hasActiveBorrowing = $records->contains(
                                fn (Equipment $record): bool => $record->hasActiveBorrowing(),
                            );

                            if ($hasActiveBorrowing) {
                                Notification::make()
                                    ->title('No se pueden eliminar los equipos seleccionados')
                                    ->body('Uno o más equipos seleccionados están asociados a un préstamo activo. Registre primero su devolución.')
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ]),
            ]);
    }

    /**
     * Determina si la tabla tiene una búsqueda o filtro activo.
     */
    private static function hasActiveSearchOrFilters(Table $table): bool
    {
        $livewire = $table->getLivewire();

        return $livewire->hasTableSearch()
            || $table->getActiveFiltersCount() > 0;
    }
}
