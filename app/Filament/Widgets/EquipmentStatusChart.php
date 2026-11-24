<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Equipment\EquipmentResource;
use App\Services\EquipmentMetricsService;
use Filament\Widgets\ChartWidget;

class EquipmentStatusChart extends ChartWidget
{
    protected ?string $heading = 'Equipos por estado';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = '15s';

    protected ?string $description = 'Distribución del inventario. Se actualiza cada 15 segundos.';

    public static function canView(): bool
    {
        return EquipmentResource::canViewAny();
    }

    protected function getData(): array
    {
        $metrics = app(EquipmentMetricsService::class)->getMetrics();

        return [
            'datasets' => [[
                'label' => 'Equipos',
                'data' => array_column(array_values($metrics['by_status']), 'count'),
                'backgroundColor' => ['#0A2D5F', '#ea580c', '#dc2626', '#6b7280'],
                'borderWidth' => 0,
            ]],
            'labels' => ['Disponibles', 'Prestados', 'En mantenimiento', 'De baja'],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
