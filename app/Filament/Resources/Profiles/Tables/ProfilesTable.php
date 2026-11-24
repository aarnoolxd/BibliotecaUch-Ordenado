<?php

namespace App\Filament\Resources\Profiles\Tables;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Models\Profile;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photo')
                    ->label('Foto')
                    ->circular()
                    ->defaultImageUrl(fn (): string => asset('images/default-avatar.svg')),

                TextColumn::make('dni')
                    ->label('DNI')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('first_name')
                    ->label('Nombres')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('last_name')
                    ->label('Apellido paterno')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('maternal_last_name')
                    ->label('Apellido materno')
                    ->placeholder('Sin apellido materno')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('program')
                    ->label('Programa')
                    ->placeholder('Sin programa')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('cycle')
                    ->label('Ciclo')
                    ->placeholder('Sin ciclo')
                    ->formatStateUsing(fn (int|string $state): string => 'Ciclo '.$state)
                    ->badge()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('user_type')
                    ->label('Tipo de usuario')
                    ->placeholder('Sin tipo registrado')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('user.email')
                    ->label('Correo de la cuenta')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Sin cuenta vinculada'),

                TextColumn::make('phone')
                    ->label('Número de celular')
                    ->placeholder('Sin celular registrado')
                    ->searchable(),

                TextColumn::make('address')
                    ->label('Dirección')
                    ->placeholder('Sin dirección registrada')
                    ->searchable()
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Fecha de Registro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_type')
                    ->label('Tipo de usuario')
                    ->placeholder('Todos los tipos')
                    ->options(Profile::USER_TYPES)
                    ->native(false),

                SelectFilter::make('program')
                    ->label('Programa académico')
                    ->placeholder('Todos los programas')
                    ->options(Profile::PROGRAMS)
                    ->native(false),

                SelectFilter::make('cycle')
                    ->label('Ciclo académico')
                    ->placeholder('Todos los ciclos')
                    ->options(Profile::CYCLES)
                    ->native(false),

                TernaryFilter::make('has_user')
                    ->label('Cuenta vinculada')
                    ->placeholder('Todos los perfiles')
                    ->trueLabel('Con cuenta vinculada')
                    ->falseLabel('Sin cuenta vinculada')
                    ->native(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('user_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('user_id'),
                    ),
            ], layout: FiltersLayout::Modal)
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->slideOver()
                    ->modalCancelAction(false),
            )
            ->recordActions([
                ProfileResource::profileDetailsAction(),
                EditAction::make(),
                DeleteAction::make()
                    ->before(function (DeleteAction $action, Profile $record): void {
                        if ($record->hasActiveBorrowing()) {
                            Notification::make()
                                ->title('No se puede eliminar el perfil')
                                ->body('Este perfil mantiene préstamos activos (en curso o vencidos). Registre primero su devolución.')
                                ->danger()
                                ->send();

                            $action->cancel();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Add bulk actions here if needed
                ]),
            ]);
    }
}
