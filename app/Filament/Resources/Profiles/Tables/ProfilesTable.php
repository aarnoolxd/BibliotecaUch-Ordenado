<?php

namespace App\Filament\Resources\Profiles\Tables;

use App\Filament\Resources\Profiles\ProfileResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                //
            ])
            ->recordActions([
                ProfileResource::profileDetailsAction(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Add bulk actions here if needed
                ]),
            ]);
    }
}
