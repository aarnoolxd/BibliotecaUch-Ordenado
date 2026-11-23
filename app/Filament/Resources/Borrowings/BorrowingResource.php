<?php

namespace App\Filament\Resources\Borrowings;

use App\Filament\Resources\Borrowings\Pages\CreateBorrowing;
use App\Filament\Resources\Borrowings\Pages\EditBorrowing;
use App\Filament\Resources\Borrowings\Pages\ListBorrowings;
use App\Filament\Resources\Borrowings\Pages\ViewBorrowing;
use App\Filament\Resources\Borrowings\Schemas\BorrowingForm;
use App\Filament\Resources\Borrowings\Tables\BorrowingTable;
use App\Models\Borrowing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BorrowingResource extends Resource
{
    protected static ?string $model = Borrowing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Bookmark;

    protected static string|UnitEnum|null $navigationGroup = 'Gestión de Préstamos';
    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Préstamo';
    protected static ?string $pluralModelLabel = 'Módulo Préstamos';

    public static function getModelLabel(): string
    {
        return 'Préstamo';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Módulo Préstamos';
    }

    public static function form(Schema $schema): Schema
    {
        return BorrowingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BorrowingTable::configure($table);
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
            'index'  => ListBorrowings::route('/'),
            'create' => CreateBorrowing::route('/create'),
            'view'   => ViewBorrowing::route('/{record}'),
            'edit'   => EditBorrowing::route('/{record}/edit'),
        ];
    }
}
