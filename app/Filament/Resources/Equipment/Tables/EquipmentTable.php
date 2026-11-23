<?php

namespace App\Filament\Resources\Equipment\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EquipmentTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patrimonial_code')
                    ->label('Cód. Patrimonial')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

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

                TextColumn::make('serial_number')
                    ->label('N° de Serie')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Número de serie copiado al portapapeles'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Disponible'    => 'success',
                        'Prestado'      => 'warning',
                        'Mantenimiento' => 'danger',
                        'Baja'          => 'gray',
                        default         => 'secondary',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Fecha Registro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Filtrar por Categoría')
                    ->options([
                        'Computadora' => 'Computadora',
                        'Laptop'      => 'Laptop',
                        'Periférico'  => 'Periférico',
                        'Otro'        => 'Otro',
                    ]),

                SelectFilter::make('status')
                    ->label('Filtrar por Estado')
                    ->options([
                        'Disponible'    => 'Disponible',
                        'Prestado'      => 'Prestado',
                        'Mantenimiento' => 'Mantenimiento',
                        'Baja'          => 'Baja',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
