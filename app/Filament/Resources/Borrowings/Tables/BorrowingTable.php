<?php

namespace App\Filament\Resources\Borrowings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
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
                    ->default(fn ($record) => $record->profile?->full_name ?? 'Estudiante no vinculado')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('equipment.patrimonial_code')
                    ->label('Cód. Patrimonial')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('equipment.model')
                    ->label('Equipo Prestado')
                    ->description(fn ($record) => $record->equipment ? "{$record->equipment->type} - {$record->equipment->brand}" : null)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('borrowing_date')
                    ->label('Fecha Préstamo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Límite Devolución')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'En curso' => 'info',
                        'Devuelto' => 'success',
                        'Vencido' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
