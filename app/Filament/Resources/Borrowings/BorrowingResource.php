<?php

namespace App\Filament\Resources\Borrowings;

use App\Filament\Resources\Borrowings\Pages\CreateBorrowing;
use App\Filament\Resources\Borrowings\Pages\EditBorrowing;
use App\Filament\Resources\Borrowings\Pages\ListBorrowings;
use App\Filament\Resources\Borrowings\Schemas\BorrowingForm;
use App\Filament\Resources\Borrowings\Schemas\BorrowingInfolist;
use App\Filament\Resources\Borrowings\Tables\BorrowingTable;
use App\Models\Borrowing;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
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

    public static function infolist(Schema $schema): Schema
    {
        return BorrowingInfolist::configure($schema);
    }

    public static function borrowingDetailsAction(): ViewAction
    {
        return ViewAction::make()
            ->label('Ver')
            ->icon(Heroicon::OutlinedEye)
            ->color('primary')
            ->modalHeading('Ficha del préstamo')
            ->modalDescription('Consulta los recursos asignados, cronología, observaciones e incidencias en un solo lugar.')
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalCancelAction(fn (Action $action): Action => $action->label('Cerrar ficha'));
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
            'index' => ListBorrowings::route('/'),
            'create' => CreateBorrowing::route('/create'),
            'edit' => EditBorrowing::route('/{record}/edit'),
        ];
    }
}
