<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Borrowing;
use App\Services\BorrowingAnalyticsService;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\On;

class AfluenceDaysHoursChart extends ChartWidget
{
    protected ?string $heading = 'Días y horarios con mayor afluencia';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    protected ?string $maxHeight = '280px';

    public ?string $filter = 'days';

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
        $base = 'Distribución de préstamos por día laboral o franja horaria (pico resaltado en naranja).';

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
            'days' => 'Afluencia por día de la semana',
            'hours' => 'Afluencia por franjas horarias',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $dimension = $this->filter === 'hours' ? 'hours' : 'days';
        $result = app(BorrowingAnalyticsService::class)->getAfluenceByDaysAndHours(
            dimension: $dimension,
            period: 'all',
            status: $this->borrowingStatus,
        );

        if ($result['empty']) {
            return [
                'datasets' => [[
                    'label' => 'Sin actividad',
                    'data' => [0],
                    'backgroundColor' => ['#9ca3af'],
                ]],
                'labels' => ['Sin actividad registrada para el filtro seleccionado'],
            ];
        }

        return [
            'datasets' => [[
                'label' => $dimension === 'hours' ? 'Préstamos por franja horaria' : 'Préstamos por día',
                'data' => $result['data'],
                'backgroundColor' => $result['backgroundColors'],
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
