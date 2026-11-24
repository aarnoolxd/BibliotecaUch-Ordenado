<?php

namespace App\Services;

use App\Models\Borrowing;
use App\Models\Equipment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BorrowingAnalyticsService
{
    /**
     * Resumen de préstamos por estado para el gráfico de distribución.
     *
     * @return array{total: int, in_progress: int, returned: int, overdue: int}
     */
    public function getOperationalSummary(?string $period = 'all'): array
    {
        $cutoff = $this->resolvePeriodStart($period);

        $counts = Borrowing::query()
            ->when($cutoff !== null, fn ($query) => $query->where('borrowing_date', '>=', $cutoff))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'in_progress' => (int) ($counts[Borrowing::STATUS_IN_PROGRESS] ?? 0),
            'returned' => (int) ($counts[Borrowing::STATUS_RETURNED] ?? 0),
            'overdue' => (int) ($counts[Borrowing::STATUS_OVERDUE] ?? 0),
        ];
    }

    /**
     * HU-19 (UB-27): Ranking de los recursos tecnológicos más solicitados
     * contabilizando equipos principales (borrowings.equipment_id) y dispositivos
     * adicionales (borrowing_equipment.equipment_id).
     *
     * @return array{labels: list<string>, data: list<int>, empty: bool}
     */
    public function getMostRequestedResources(?string $period = 'all', int $limit = 5): array
    {
        $cutoff = $this->resolvePeriodStart($period);

        $primaryQuery = DB::table('borrowings')
            ->selectRaw('id as borrowing_id, equipment_id')
            ->whereNotNull('equipment_id')
            ->when($cutoff !== null, fn ($query) => $query->where('borrowing_date', '>=', $cutoff));

        $additionalQuery = DB::table('borrowing_equipment')
            ->join('borrowings', 'borrowing_equipment.borrowing_id', '=', 'borrowings.id')
            ->select('borrowing_equipment.borrowing_id', 'borrowing_equipment.equipment_id')
            ->when($cutoff !== null, fn ($query) => $query->where('borrowings.borrowing_date', '>=', $cutoff));

        $records = DB::query()
            ->fromSub($primaryQuery->union($additionalQuery), 'requested_resources')
            ->selectRaw('equipment_id, COUNT(*) as total_loans')
            ->groupBy('equipment_id')
            ->orderByDesc('total_loans')
            ->orderBy('equipment_id')
            ->limit(max(1, $limit))
            ->get();

        if ($records->isEmpty()) {
            return [
                'labels' => [],
                'data' => [],
                'empty' => true,
            ];
        }

        $equipmentIds = $records
            ->pluck('equipment_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $equipments = Equipment::query()
            ->whereIn('id', $equipmentIds)
            ->get()
            ->keyBy('id');

        $labels = [];
        $data = [];

        foreach ($records as $item) {
            $equipmentId = (int) $item->equipment_id;
            /** @var Equipment|null $equipment */
            $equipment = $equipments->get($equipmentId);

            if ($equipment !== null) {
                $labels[] = "[{$equipment->patrimonial_code}] {$equipment->brand} {$equipment->model}";
            } else {
                $labels[] = "Equipo #{$equipmentId}";
            }

            $data[] = (int) $item->total_loans;
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'empty' => false,
        ];
    }

    /**
     * HU-20 (UB-28): Consolidado de afluencia de préstamos agrupados por ciclo académico (I al X).
     *
     * @return array{labels: list<string>, data: list<int>, backgroundColors: list<string>, empty: bool, max_cycle: ?int}
     */
    public function getAfluenceByCycle(?string $period = 'all'): array
    {
        $cutoff = $this->resolvePeriodStart($period);

        $counts = Borrowing::query()
            ->join('profiles', 'borrowings.profile_id', '=', 'profiles.id')
            ->whereNotNull('profiles.cycle')
            ->whereBetween('profiles.cycle', [1, 10])
            ->when($cutoff !== null, fn ($query) => $query->where('borrowings.borrowing_date', '>=', $cutoff))
            ->selectRaw('profiles.cycle, COUNT(borrowings.id) as total')
            ->groupBy('profiles.cycle')
            ->pluck('total', 'profiles.cycle')
            ->all();

        $romanCycles = [
            1 => 'Ciclo I',
            2 => 'Ciclo II',
            3 => 'Ciclo III',
            4 => 'Ciclo IV',
            5 => 'Ciclo V',
            6 => 'Ciclo VI',
            7 => 'Ciclo VII',
            8 => 'Ciclo VIII',
            9 => 'Ciclo IX',
            10 => 'Ciclo X',
        ];

        $labels = [];
        $data = [];
        $maxVal = 0;
        $totalLoans = 0;

        foreach ($romanCycles as $cycleNum => $romanLabel) {
            $val = (int) ($counts[$cycleNum] ?? 0);
            $labels[] = $romanLabel;
            $data[] = $val;
            $totalLoans += $val;

            if ($val > $maxVal) {
                $maxVal = $val;
            }
        }

        $backgroundColors = [];

        foreach ($data as $val) {
            if ($val > 0 && $val === $maxVal) {
                $backgroundColors[] = '#ea580c';
            } else {
                $backgroundColors[] = '#0A2D5F';
            }
        }

        $maxIndex = $maxVal > 0 ? array_search($maxVal, $data, true) : false;

        return [
            'labels' => $labels,
            'data' => $data,
            'backgroundColors' => $backgroundColors,
            'empty' => $totalLoans === 0,
            'max_cycle' => $maxIndex !== false ? ((int) $maxIndex + 1) : null,
        ];
    }

    /**
     * HU-21 (UB-29): Afluencia distribuida por días de la semana (Lunes–Sábado)
     * o por franjas horarias de 2 horas (08:00–20:00).
     *
     * @return array{labels: list<string>, data: list<int>, backgroundColors: list<string>, empty: bool}
     */
    public function getAfluenceByDaysAndHours(string $dimension = 'days', ?string $period = 'all'): array
    {
        $cutoff = $this->resolvePeriodStart($period);

        $rows = Borrowing::query()
            ->toBase()
            ->select('borrowing_date')
            ->whereNotNull('borrowing_date')
            ->when($cutoff !== null, fn ($query) => $query->where('borrowing_date', '>=', $cutoff))
            ->cursor();

        if ($dimension === 'days') {
            $dayLabels = [
                1 => 'Lunes',
                2 => 'Martes',
                3 => 'Miércoles',
                4 => 'Jueves',
                5 => 'Viernes',
                6 => 'Sábado',
            ];

            $dayCounts = array_fill(1, 6, 0);

            foreach ($rows as $row) {
                $isoDay = Carbon::parse($row->borrowing_date)->isoWeekday();

                if (isset($dayCounts[$isoDay])) {
                    $dayCounts[$isoDay]++;
                }
            }

            $total = array_sum($dayCounts);

            if ($total === 0) {
                return [
                    'labels' => [],
                    'data' => [],
                    'backgroundColors' => [],
                    'empty' => true,
                ];
            }

            $maxVal = max($dayCounts);
            $colors = [];

            foreach ($dayCounts as $count) {
                $colors[] = ($count > 0 && $count === $maxVal) ? '#ea580c' : '#2563eb';
            }

            return [
                'labels' => array_values($dayLabels),
                'data' => array_values($dayCounts),
                'backgroundColors' => $colors,
                'empty' => false,
            ];
        }

        $hourBlocks = [
            '08:00 - 10:00' => 0,
            '10:00 - 12:00' => 0,
            '12:00 - 14:00' => 0,
            '14:00 - 16:00' => 0,
            '16:00 - 18:00' => 0,
            '18:00 - 20:00' => 0,
        ];

        foreach ($rows as $row) {
            $hour = Carbon::parse($row->borrowing_date)->hour;

            if ($hour >= 8 && $hour < 10) {
                $hourBlocks['08:00 - 10:00']++;
            } elseif ($hour >= 10 && $hour < 12) {
                $hourBlocks['10:00 - 12:00']++;
            } elseif ($hour >= 12 && $hour < 14) {
                $hourBlocks['12:00 - 14:00']++;
            } elseif ($hour >= 14 && $hour < 16) {
                $hourBlocks['14:00 - 16:00']++;
            } elseif ($hour >= 16 && $hour < 18) {
                $hourBlocks['16:00 - 18:00']++;
            } elseif ($hour >= 18 && $hour < 20) {
                $hourBlocks['18:00 - 20:00']++;
            }
        }

        $total = array_sum($hourBlocks);

        if ($total === 0) {
            return [
                'labels' => [],
                'data' => [],
                'backgroundColors' => [],
                'empty' => true,
            ];
        }

        $maxVal = max($hourBlocks);
        $colors = [];

        foreach ($hourBlocks as $count) {
            $colors[] = ($count > 0 && $count === $maxVal) ? '#ea580c' : '#0A2D5F';
        }

        return [
            'labels' => array_keys($hourBlocks),
            'data' => array_values($hourBlocks),
            'backgroundColors' => $colors,
            'empty' => false,
        ];
    }

    /**
     * Resuelve el instante inicial según el periodo seleccionado.
     */
    private function resolvePeriodStart(?string $period): ?Carbon
    {
        return match ($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'semester' => now()->subMonths(6),
            default => null,
        };
    }
}
