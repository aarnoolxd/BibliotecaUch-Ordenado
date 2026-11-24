<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Filament\Resources\Equipment\EquipmentResource;
use App\Models\Borrowing;
use App\Services\BorrowingAnalyticsService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BorrowingOverviewStats extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected int|array|null $columns = 3;

    protected ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        return BorrowingResource::canViewAny();
    }

    protected function getStats(): array
    {
        $summary = app(BorrowingAnalyticsService::class)->getOperationalSummary();

        $inProgressUrl = BorrowingResource::getUrl('index', [
            'filters' => [
                'status' => ['value' => Borrowing::STATUS_IN_PROGRESS],
            ],
        ]);

        $overdueUrl = BorrowingResource::getUrl('index', [
            'filters' => [
                'status' => ['value' => Borrowing::STATUS_OVERDUE],
            ],
        ]);

        $maintenanceStat = Stat::make('Equipos en Mantenimiento', $summary['in_maintenance'])
            ->description(
                $summary['total_incidents'] === 1
                    ? '1 incidencia registrada en historial'
                    : "{$summary['total_incidents']} incidencias registradas en historial"
            )
            ->descriptionIcon(Heroicon::OutlinedWrenchScrewdriver)
            ->color($summary['in_maintenance'] > 0 ? 'warning' : 'gray');

        if (EquipmentResource::canViewAny()) {
            $maintenanceStat->url(EquipmentResource::getUrl('index'));
        }

        return [
            Stat::make('Préstamos En Curso', $summary['in_progress'])
                ->description('Préstamos activos vigentes')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('info')
                ->url($inProgressUrl),

            Stat::make('Préstamos Vencidos', $summary['overdue'])
                ->description($summary['overdue'] > 0 ? 'Requieren devolución inmediata' : 'Sin préstamos vencidos')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($summary['overdue'] > 0 ? 'danger' : 'success')
                ->url($overdueUrl),

            $maintenanceStat,
        ];
    }
}
