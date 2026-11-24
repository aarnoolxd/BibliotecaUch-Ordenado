<?php

namespace App\Filament\Resources\Equipment\Tables;

use App\Filament\Resources\Equipment\EquipmentResource;
use App\Models\Equipment;
use App\Services\ExportHistoryExcelService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['incidents.borrowing.profile', 'incidents.equipment'])
                ->withCount(['borrowings', 'additionalBorrowings']))

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
                ImageColumn::make('photo')
                    ->label('Foto')
                    ->square()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->color(fn (string $state): string => Equipment::statusColor($state))
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
                ActionGroup::make([
                    EquipmentResource::equipmentDetailsAction(),
                    Action::make('export_usage_history')
                        ->label('Historial de Uso Excel')
                        ->icon(Heroicon::OutlinedDocumentArrowDown)
                        ->color('success')
                        ->authorize(fn (Equipment $record): bool => ($user = auth()->user()) !== null
                            && $user->can('view', $record)
                            && $user->can('ViewAny_Borrowing')
                            && $user->can('View_Profile'))
                        ->action(function (Equipment $record, ExportHistoryExcelService $service) {
                            $user = auth()->user();

                            abort_unless(
                                $user !== null
                                && $user->can('view', $record)
                                && $user->can('ViewAny_Borrowing')
                                && $user->can('View_Profile'),
                                403,
                            );

                            return $service->exportEquipmentHistory($record);
                        }),
                    EditAction::make(),
                    Action::make('discontinue')
                        ->label('Descontinuar')
                        ->icon(Heroicon::OutlinedArchiveBoxXMark)
                        ->color('danger')
                        ->visible(fn (Equipment $record): bool => $record->status !== Equipment::STATUS_DISCONTINUED)
                        ->authorize('update')
                        ->modalHeading('Descontinuar equipo')
                        ->modalDescription('Pasará a estado Baja y no podrá prestarse.')
                        ->modalSubmitActionLabel('Descontinuar')
                        ->modalCancelActionLabel('Cancelar')
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
                                    ->title('No se puede descontinuar')
                                    ->body('Tiene un préstamo activo. Registra la devolución primero.')
                                    ->danger()
                                    ->persistent()
                                    ->send(),
                                Equipment::DISCONTINUE_ALREADY_DISCONTINUED => Notification::make()
                                    ->title('Ya está de baja')
                                    ->body("{$record->patrimonial_code} ya figura en estado Baja.")
                                    ->warning()
                                    ->send(),
                                Equipment::DISCONTINUE_SUCCESS => Notification::make()
                                    ->title('Equipo descontinuado')
                                    ->body("{$record->patrimonial_code} pasó a estado Baja.")
                                    ->success()
                                    ->send(),
                                default => Notification::make()
                                    ->title('Equipo no encontrado')
                                    ->body('Este equipo ya no existe.')
                                    ->danger()
                                    ->send(),
                            };
                        }),
                    DeleteAction::make()
                        ->modalHeading('¿Eliminar equipo?')
                        ->modalDescription('Esta acción no se puede deshacer.')
                        ->modalSubmitActionLabel('Eliminar')
                        ->modalCancelActionLabel('Cancelar')
                        ->successNotificationTitle('Equipo eliminado')
                        ->before(function (DeleteAction $action, Equipment $record): void {
                            if ($record->hasActiveBorrowing()) {
                                Notification::make()
                                    ->title('No se puede eliminar')
                                    ->body('Tiene un préstamo activo.')
                                    ->danger()
                                    ->send();

                                $action->cancel();
                            }
                        }),
                ])
                    ->label('Acciones')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->size(Size::Small)
                    ->color('gray')
                    ->button(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalHeading('¿Eliminar equipos?')
                        ->modalDescription('Esta acción no se puede deshacer.')
                        ->modalSubmitActionLabel('Eliminar')
                        ->modalCancelActionLabel('Cancelar')
                        ->successNotificationTitle('Equipos eliminados')
                        ->before(function (DeleteBulkAction $action, Collection $records): void {
                            $hasActiveBorrowing = $records->contains(
                                fn (Equipment $record): bool => $record->hasActiveBorrowing(),
                            );

                            if ($hasActiveBorrowing) {
                                Notification::make()
                                    ->title('No se pueden eliminar')
                                    ->body('Hay equipos con préstamos activos.')
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
