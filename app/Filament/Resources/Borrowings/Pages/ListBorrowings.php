<?php

namespace App\Filament\Resources\Borrowings\Pages;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Borrowing;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBorrowings extends ListRecords
{
    protected static string $resource = BorrowingResource::class;

    public function mount(): void
    {
        Borrowing::markOverdueBorrowings();

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Registrar Préstamo'),
        ];
    }
}
