<?php

namespace App\Filament\Resources\Permissions;

use App\Filament\Resources\Permissions\Pages\CreatePermission;
use App\Filament\Resources\Permissions\Pages\EditPermission;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\Schemas\PermissionForm;
use App\Filament\Resources\Permissions\Tables\PermissionsTable;
use App\Models\Permission;
use BackedEnum;
use unitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PermissionResource extends Resource
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Key;


    protected static string|\UnitEnum|null $navigationGroup = 'Gestión de Seguridad';

    protected static ?int $navigationSort = 1;
    protected static ?string $model = Permission::class;

    public static function form(Schema $schema): Schema
    {
        return PermissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PermissionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermissions::route('/'),
            'create' => CreatePermission::route('/create'),
            'edit' => EditPermission::route('/edit/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'Permiso';
    }
    public static function getPluralModelLabel(): string
    {
        return 'Módulo Permisos';
    }

}
