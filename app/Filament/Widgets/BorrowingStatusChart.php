<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Services\BorrowingAnalyticsService;
use Filament\Widgets\ChartWidget;

class BorrowingStatusChart extends ChartWidget
{
    protected ?string $heading = 'Préstamos por estado';

    protected ?string $description = 'Distribución de préstamos registrados.';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    protected ?string $maxHeight = '320px';

    protected ?string $pollingInterval = '15s';

    public ?string $filter = 'all';

    public static function canView(): bool
    {
        return BorrowingResource::canViewAny();
    }

    /**
     * @return array<string, string>|null
     */
    protected function getFilters(): ?array
    {
        return [
            'all' => 'Todo el historial',
            'semester' => 'Último semestre',
            'month' => 'Último mes',
            'week' => 'Última semana',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $summary = app(BorrowingAnalyticsService::class)->getOperationalSummary($this->filter);

        return [
            'datasets' => [[
                'label' => 'Préstamos',
                'data' => [
                    $summary['in_progress'],
                    $summary['returned'],
                    $summary['overdue'],
                ],
                'backgroundColor' => ['#2563eb', '#059669', '#dc2626'],
                'borderRadius' => 6,
                'barThickness' => 26,
            ]],
            'labels' => ['En curso', 'Devueltos', 'Vencidos'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
