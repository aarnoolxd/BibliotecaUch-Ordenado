<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Services\BorrowingAnalyticsService;
use Filament\Widgets\ChartWidget;

class StudentCycleAfluenceChart extends ChartWidget
{
    protected ?string $heading = 'Afluencia de préstamos por ciclo académico';

    protected ?string $description = 'Préstamos estudiantiles del Ciclo I al X (el ciclo pico se resalta en naranja).';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    protected ?string $maxHeight = '280px';

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
            'semester' => 'Semestre actual',
            'month' => 'Último mes',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $result = app(BorrowingAnalyticsService::class)->getAfluenceByCycle($this->filter);

        if ($result['empty']) {
            return [
                'datasets' => [[
                    'label' => 'Sin afluencia',
                    'data' => [0],
                    'backgroundColor' => ['#9ca3af'],
                ]],
                'labels' => ['Sin registros de afluencia'],
            ];
        }

        return [
            'datasets' => [[
                'label' => 'Préstamos por ciclo',
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
