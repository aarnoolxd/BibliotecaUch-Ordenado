<?php

namespace App\Filament\Resources\Equipment\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Panel;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
            ->striped()

            // UB-117: Mensaje explicativo cuando el inventario esté vacío o sin coincidencias (Criterio 5 HU-04)
            ->emptyStateIcon(Heroicon::OutlinedComputerDesktop)
            ->emptyStateHeading(fn (Table $table): string => self::hasActiveSearchOrFilters($table)
                ? 'No se encontraron equipos'
                : 'Sin equipos registrados')
            ->emptyStateDescription(fn (Table $table): string => self::hasActiveSearchOrFilters($table)
                ? 'No hay equipos que coincidan con la búsqueda o los filtros aplicados.'
                : 'El inventario aún no tiene equipos registrados. Haz clic en "Crear Equipo" para añadir el primer recurso.')

            // UB-116: Columnas principales del listado (Criterio 2 HU-04)
            ->columns([
                Split::make([
                    TextColumn::make('patrimonial_code')
                        ->label('Cód. Patrimonial')
                        ->searchable()
                        ->sortable()
                        ->weight('bold')
                        ->icon(Heroicon::OutlinedComputerDesktop),

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
                            'Baja' => 'gray',
                            default => 'secondary',
                        })
                        ->sortable(),
                ]),

                // UB-119: Panel expandible / acordeón con información secundaria (Criterio 3 HU-04)
                Panel::make([
                    Stack::make([
                        TextColumn::make('serial_number')
                            ->label('N° de Serie')
                            ->icon(Heroicon::OutlinedFingerPrint)
                            ->searchable()
                            ->copyable()
                            ->copyMessage('Número de serie copiado al portapapeles'),

                        TextColumn::make('observations')
                            ->label('Observaciones')
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->placeholder('Sin observaciones registradas.')
                            ->wrap(),

                        TextColumn::make('created_at')
                            ->label('Fecha de Registro')
                            ->icon(Heroicon::OutlinedCalendar)
                            ->dateTime('d/m/Y H:i')
                            ->sortable(),
                    ]),
                ])->collapsible(),
            ])

            // Filtros por categoría y estado (Criterio 3 HU-04)
            ->filters([
                SelectFilter::make('type')
                    ->label('Filtrar por Categoría')
                    ->options([
                        'Computadora' => 'Computadora',
                        'Laptop' => 'Laptop',
                        'Periférico' => 'Periférico',
                        'Otro' => 'Otro',
                    ]),

                SelectFilter::make('status')
                    ->label('Filtrar por Estado')
                    ->options([
                        'Disponible' => 'Disponible',
                        'Prestado' => 'Prestado',
                        'Mantenimiento' => 'Mantenimiento',
                        'Baja' => 'Baja',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Determina si la tabla tiene una búsqueda, un filtro o una pestaña de estado activa.
     */
    private static function hasActiveSearchOrFilters(Table $table): bool
    {
        $livewire = $table->getLivewire();

        $hasActiveTabFilter = property_exists($livewire, 'activeTab')
            && filled($livewire->activeTab)
            && $livewire->activeTab !== 'todos';

        return $livewire->hasTableSearch()
            || $table->getActiveFiltersCount() > 0
            || $hasActiveTabFilter;
    }
}
