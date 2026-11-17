<?php

namespace App\Services;

use App\Models\Equipment;
use Illuminate\Support\Collection;

class EquipmentMetricsService
{
    /**
     * Obtiene el consolidado de métricas por estado, calculando totales y porcentajes.
     * Permite filtrar opcionalmente por categoría/tipo de equipo.
     *
     * @param string|null $type
     * @return array
     */
    public function getMetrics(?string $type = null): array
    {
        $query = Equipment::query();

        if (filled($type)) {
            $query->where('type', $type);
        }

        $total = (clone $query)->count();

        $statuses = [
            'Disponible'    => ['count' => 0, 'percentage' => 0.0, 'color' => 'success'],
            'Prestado'      => ['count' => 0, 'percentage' => 0.0, 'color' => 'warning'],
            'Mantenimiento' => ['count' => 0, 'percentage' => 0.0, 'color' => 'danger'],
            'Baja'          => ['count' => 0, 'percentage' => 0.0, 'color' => 'gray'],
        ];

        if ($total > 0) {
            $counts = (clone $query)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            foreach ($statuses as $statusKey => &$data) {
                $count = (int) ($counts[$statusKey] ?? 0);
                $data['count'] = $count;
                $data['percentage'] = round(($count / $total) * 100, 1);
            }
            unset($data);
        }

        return [
            'total'     => $total,
            'type'      => $type,
            'by_status' => $statuses,
        ];
    }

    /**
     * Obtiene el consolidado de métricas agrupadas por categoría y desglosadas por estado.
     *
     * @return Collection
     */
    public function getGroupedByCategory(): Collection
    {
        $types = ['Computadora', 'Laptop', 'Periférico', 'Otro'];

        return collect($types)->map(function (string $type): array {
            return $this->getMetrics($type);
        });
    }
}
