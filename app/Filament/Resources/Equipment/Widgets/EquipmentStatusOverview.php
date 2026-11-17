<?php

namespace App\Filament\Resources\Equipment\Widgets;

use App\Services\EquipmentMetricsService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EquipmentStatusOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $service = app(EquipmentMetricsService::class);
        $metrics = $service->getMetrics();

        $total = $metrics['total'];
        $byStatus = $metrics['by_status'];

        $disp = $byStatus['Disponible'];
        $prest = $byStatus['Prestado'];
        $mant = $byStatus['Mantenimiento'];
        $baja = $byStatus['Baja'];

        return [
            Stat::make('Total Global de Equipos', (string) $total)
                ->description('Inventario consolidado')
                ->descriptionIcon(Heroicon::OutlinedComputerDesktop)
                ->color('primary'),

            Stat::make('Disponibles', "{$disp['count']} ({$disp['percentage']}%)")
                ->description('Listos para asignación/préstamo')
                ->descriptionIcon(Heroicon::OutlinedCheckCircle)
                ->color('success'),

            Stat::make('Prestados', "{$prest['count']} ({$prest['percentage']}%)")
                ->description('Actualmente en préstamo')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('warning'),

            Stat::make('En Mantenimiento', "{$mant['count']} ({$mant['percentage']}%)")
                ->description('Revisión técnica o reparación')
                ->descriptionIcon(Heroicon::OutlinedWrenchScrewdriver)
                ->color('danger'),

            Stat::make('De Baja', "{$baja['count']} ({$baja['percentage']}%)")
                ->description('Equipos desincorporados')
                ->descriptionIcon(Heroicon::OutlinedArchiveBoxXMark)
                ->color('gray'),
        ];
    }
}
