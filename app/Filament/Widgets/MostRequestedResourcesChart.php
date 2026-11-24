<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Borrowing;
use App\Services\BorrowingAnalyticsService;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\On;

class MostRequestedResourcesChart extends ChartWidget
{
    protected ?string $heading = 'Recursos más solicitados';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = '30s';

    public ?string $filter = 'all';

    public ?string $borrowingStatus = null;

    public static function canView(): bool
    {
        return BorrowingResource::canViewAny();
    }

    #[On(BorrowingOverviewStats::STATUS_FILTER_EVENT)]
    public function updateBorrowingStatus(?string $status): void
    {
        $this->borrowingStatus = in_array($status, [
            Borrowing::STATUS_IN_PROGRESS,
            Borrowing::STATUS_RETURNED,
            Borrowing::STATUS_OVERDUE,
        ], true) ? $status : null;

        $this->updateChartData();
    }

    public function getDescription(): ?string
    {
        $base = 'Top 5 equipos y periféricos con mayor demanda. Se actualiza cada 30 segundos.';

        if ($this->borrowingStatus !== null) {
            return "{$base} Estado seleccionado: {$this->borrowingStatus}.";
        }

        return $base;
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
        $result = app(BorrowingAnalyticsService::class)->getMostRequestedResources(
            period: $this->filter,
            limit: 5,
            status: $this->borrowingStatus,
        );

        if ($result['empty']) {
            return [
                'datasets' => [[
                    'label' => 'Sin préstamos en este periodo',
                    'data' => [0],
                    'backgroundColor' => ['#9ca3af'],
                ]],
                'labels' => ['No hay datos disponibles para el filtro seleccionado'],
            ];
        }

        return [
            'datasets' => [[
                'label' => 'Cantidad de préstamos',
                'data' => $result['data'],
                'backgroundColor' => array_slice(
                    ['#0A2D5F', '#1e40af', '#2563eb', '#3b82f6', '#60a5fa'],
                    0,
                    count($result['data']),
                ),
                'borderRadius' => 4,
            ]],
            'labels' => $result['labels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
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
