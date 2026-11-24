<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Correo Electrónico')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Fecha de Creación')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Última Actualización')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Rol asignado')
                    ->placeholder('Todos los roles')
                    ->relationship('roles', 'name')
                    ->preload()
                    ->native(false),

                TernaryFilter::make('has_profile')
                    ->label('Perfil vinculado')
                    ->placeholder('Todas las cuentas')
                    ->trueLabel('Con perfil vinculado')
                    ->falseLabel('Sin perfil vinculado')
                    ->native(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('profile'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('profile'),
                    ),
            ], layout: FiltersLayout::Modal)
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->slideOver()
                    ->modalCancelAction(false),
            )
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalHeading('¿Eliminar cuenta?')
                    ->modalDescription('Esta acción no se puede deshacer.')
                    ->modalSubmitActionLabel('Eliminar')
                    ->modalCancelActionLabel('Cancelar')
                    ->successNotificationTitle('Cuenta eliminada')
                    ->before(function (DeleteAction $action, User $record): void {
                        if ($record->hasActiveBorrowing()) {
                            Notification::make()
                                ->title('No se puede eliminar')
                                ->body('Tiene préstamos activos.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                ]),
            ]);
    }
}
