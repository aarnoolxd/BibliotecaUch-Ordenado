<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Models\Borrowing;
use App\Services\BorrowingAnalyticsService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BorrowingOverviewStats extends StatsOverviewWidget
{
    public const STATUS_FILTER_EVENT = 'dashboard-borrowing-status-updated';

    protected static ?int $sort = -1;

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'lg' => 4,
    ];

    protected ?string $pollingInterval = '15s';

    public ?string $selectedStatus = null;

    public static function canView(): bool
    {
        return BorrowingResource::canViewAny();
    }

    public function selectStatus(?string $status): void
    {
        $normalized = in_array($status, [
            Borrowing::STATUS_IN_PROGRESS,
            Borrowing::STATUS_RETURNED,
            Borrowing::STATUS_OVERDUE,
        ], true) ? $status : null;

        $this->selectedStatus = ($normalized !== null && $this->selectedStatus === $normalized)
            ? null
            : $normalized;

        $this->dispatch(self::STATUS_FILTER_EVENT, status: $this->selectedStatus);
    }

    protected function getStats(): array
    {
        $summary = app(BorrowingAnalyticsService::class)->getOperationalSummary();
        $total = $summary['total'];

        return [
            $this->makeSelectableStat(
                label: 'Préstamos Registrados',
                value: $total,
                statusValue: null,
                activeColorHex: '#0A2D5F',
                color: 'primary',
                icon: Heroicon::OutlinedClipboardDocumentList,
                description: $this->selectedStatus === null
                    ? 'Filtro activo · Todos los préstamos'
                    : 'Clic para mostrar todos los préstamos',
            ),

            $this->makeSelectableStat(
                label: 'En Curso',
                value: $summary['in_progress'],
                statusValue: Borrowing::STATUS_IN_PROGRESS,
                activeColorHex: '#2563eb',
                color: 'info',
                icon: Heroicon::OutlinedClock,
                description: $this->formatStatusDescription(
                    count: $summary['in_progress'],
                    total: $total,
                    isSelected: $this->selectedStatus === Borrowing::STATUS_IN_PROGRESS,
                ),
            ),

            $this->makeSelectableStat(
                label: 'Devueltos',
                value: $summary['returned'],
                statusValue: Borrowing::STATUS_RETURNED,
                activeColorHex: '#059669',
                color: 'success',
                icon: Heroicon::OutlinedCheckCircle,
                description: $this->formatStatusDescription(
                    count: $summary['returned'],
                    total: $total,
                    isSelected: $this->selectedStatus === Borrowing::STATUS_RETURNED,
                ),
            ),

            $this->makeSelectableStat(
                label: 'Vencidos',
                value: $summary['overdue'],
                statusValue: Borrowing::STATUS_OVERDUE,
                activeColorHex: '#dc2626',
                color: $summary['overdue'] > 0 ? 'danger' : 'gray',
                icon: Heroicon::OutlinedExclamationTriangle,
                description: $this->formatStatusDescription(
                    count: $summary['overdue'],
                    total: $total,
                    isSelected: $this->selectedStatus === Borrowing::STATUS_OVERDUE,
                ),
            ),
        ];
    }

    private function makeSelectableStat(
        string $label,
        int $value,
        ?string $statusValue,
        string $activeColorHex,
        string $color,
        Heroicon $icon,
        string $description,
    ): Stat {
        $isSelected = $this->selectedStatus === $statusValue;
        $wireCall = $statusValue === null
            ? 'selectStatus(null)'
            : "selectStatus('{$statusValue}')";

        $style = $isSelected
            ? "cursor: pointer; box-shadow: 0 0 0 2px {$activeColorHex}; transition: all 150ms ease;"
            : 'cursor: pointer; opacity: 0.86; transition: all 150ms ease;';

        return Stat::make($label, $value)
            ->description($description)
            ->descriptionIcon($icon)
            ->color($color)
            ->extraAttributes([
                'wire:click' => $wireCall,
                'role' => 'button',
                'tabindex' => '0',
                'aria-pressed' => $isSelected ? 'true' : 'false',
                'x-on:keydown.enter.prevent' => '$wire.'.$wireCall,
                'x-on:keydown.space.prevent' => '$wire.'.$wireCall,
                'style' => $style,
            ]);
    }

    private function formatStatusDescription(int $count, int $total, bool $isSelected): string
    {
        $percentage = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;

        if ($isSelected) {
            return "Filtro activo · {$percentage}% del total";
        }

        return "{$percentage}% del total · Clic para filtrar";
    }
}
