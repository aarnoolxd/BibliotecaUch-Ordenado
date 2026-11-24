<?php

namespace App\Filament\Resources\Permissions\Tables;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PermissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Permiso')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('guard_name')
                    ->label('Grupo de Seguridad')
                    ->badge(),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('module')
                    ->label('Módulo del sistema')
                    ->placeholder('Todos los módulos')
                    ->options([
                        'Equipment' => 'Inventario (Equipos)',
                        'Borrowing' => 'Préstamos',
                        'Profile' => 'Perfiles',
                        'User' => 'Usuarios',
                        'Role' => 'Roles',
                        'Permission' => 'Permisos',
                    ])
                    ->native(false)
                    ->query(function (Builder $query, array $data): Builder {
                        $module = $data['value'] ?? null;
                        $allowedModules = ['Equipment', 'Borrowing', 'Profile', 'User', 'Role', 'Permission'];

                        if (! is_string($module) || ! in_array($module, $allowedModules, true)) {
                            return $query;
                        }

                        return $query->whereRaw("name LIKE ? ESCAPE '\\'", ['%\_'.$module]);
                    }),
            ], layout: FiltersLayout::Modal)
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->slideOver()
                    ->modalCancelAction(false),
            );
    }
}
