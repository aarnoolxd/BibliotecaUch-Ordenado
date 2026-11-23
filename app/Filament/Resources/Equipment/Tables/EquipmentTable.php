<?php

namespace App\Filament\Resources\Equipment\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
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
            ->filters([])
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
     * Determina si la tabla tiene una búsqueda o filtro activo.
     */
    private static function hasActiveSearchOrFilters(Table $table): bool
    {
        $livewire = $table->getLivewire();

        return $livewire->hasTableSearch()
            || $table->getActiveFiltersCount() > 0;
    }
}
