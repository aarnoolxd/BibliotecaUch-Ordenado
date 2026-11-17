<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    // Íconos de navegación normal y activo
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Users;

    // Agrupación en el menú lateral y orden de aparición
    protected static string|UnitEnum|null $navigationGroup = 'Gestión de Seguridad';
    protected static ?int $navigationSort = 3;

    // Etiquetas del modelo en singular y plural
    protected static ?string $modelLabel = 'Cuenta';
    protected static ?string $pluralModelLabel = 'Módulo Cuentas';

    public static function getModelLabel(): string
    {
        return 'Cuenta';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Módulo Cuentas';
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
