<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Borrowing;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BorrowingOverviewStats extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    protected int|array|null $columns = 1;

    protected ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        return BorrowingResource::canViewAny();
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Préstamos Totales', Borrowing::count())
                ->description('Historial global')
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentList)
                ->color('primary'),
        ];
    }
}
