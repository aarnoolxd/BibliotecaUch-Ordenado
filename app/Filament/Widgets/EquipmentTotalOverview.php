<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Equipment\EquipmentResource;
use App\Models\Equipment;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EquipmentTotalOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected int|array|null $columns = 1;

    protected ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        return EquipmentResource::canViewAny();
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Equipos Totales', Equipment::count())
                ->description('Inventario global')
                ->descriptionIcon(Heroicon::OutlinedComputerDesktop)
                ->color('primary'),
        ];
    }
}
